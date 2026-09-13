<?php

declare(strict_types=1);

/**
 * P5-4 提醒链路自检（事务内造数 → 跑 coding-plan:remind-promotions → 断言 → 回滚零残留）：
 *  1) dry-run 只统计不写入；
 *  2) 实跑#1：公告流水(user_id=0) + Notice 追加 + 订阅用户邮件流水 + 过期活动自动置 2；
 *  3) 实跑#2：unique(promotion_id,kind,user_id) 防重发（流水/公告零新增）；
 *  4) 删流水 → 实跑#3：强制重发一次；
 *  5) 窗口外（remind_days=7、剩 8 天）跳过且不被置过期。
 * 邮件经 MAIL_MAILER=log 落日志（无真实外发）；队列强制 sync 防 redis 残留测试 job。
 * 运行：php tests/coding-plan-remind-verify.php
 */

use App\Models\Option;
use App\Services\OptionService;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

require __DIR__.'/../vendor/autoload.php';
$app = require __DIR__.'/../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

config(['queue.default' => 'sync']);
// Option::get 有 60s redis 缓存；事务回滚不撤销缓存 → 开跑前主动清，保证 Notice 基线干净
Cache::forget('option:Notice');
Cache::forget(Option::AGGREGATE_CACHE_KEY);

$pass = 0;
$fail = 0;
$check = function (string $name, bool $ok) use (&$pass, &$fail): void {
    echo ($ok ? '✅ ' : '❌ ').$name."\n";
    $ok ? $pass++ : $fail++;
};

DB::beginTransaction();
try {
    $now = time();
    $suffix = substr((string) md5((string) mt_rand()), 0, 8);

    // —— 造数：订阅用户 + 套餐 + 订阅 + 三个测试活动（窗口内/窗口外/已过期）——
    $userId = (int) DB::table('users')->insertGetId([
        'username' => 'p54verify_'.$suffix,
        'password' => bcrypt('p54verify'),
        'aff_code' => 'p54'.$suffix,
        'display_name' => 'P5-4 自检用户',
        'email' => 'p54verify_'.$suffix.'@example.invalid',
        'role' => 1,
        'status' => 1,
        'created_at' => $now,
    ]);
    $planId = (int) DB::table('subscription_plans')->insertGetId([
        'name' => '[P5-4 自检] 套餐',
        'price' => 0,
        'plan_type' => 'coding',
        'coding_vendor' => 'zhipu',
        'status' => 1,
        'created_at' => $now,
        'updated_at' => $now,
    ]);
    DB::table('subscriptions')->insert([
        'user_id' => $userId,
        'plan_id' => $planId,
        'status' => 1,
        'period_start' => $now - 86400,
        'period_end' => $now + 30 * 86400,
        'created_at' => $now,
        'updated_at' => $now,
    ]);

    $mkPromo = function (string $tag, int $endsAt) use ($now): int {
        return (int) DB::table('coding_plan_promotions')->insertGetId([
            'vendor' => 'zhipu',
            'kind' => 'discount',
            'title' => '[P5-4 自检] '.$tag,
            'description' => '自检数据',
            'starts_at' => 0,
            'ends_at' => $endsAt,
            'status' => 1,
            'remind_days' => 7,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
    };
    $promoInWindow = $mkPromo('窗口内', $now + 86400);
    $promoOutside = $mkPromo('窗口外', $now + 8 * 86400);
    $promoExpired = $mkPromo('已过期', $now - 3600);

    $reminders = fn () => DB::table('coding_plan_promotion_reminders')
        ->whereIn('promotion_id', [$promoInWindow, $promoOutside, $promoExpired])
        ->get();
    $noticeAt = fn () => (string) OptionService::get('Notice', '');
    $noticeBefore = $noticeAt();

    // —— 1) dry-run：只统计不写入 ——
    Artisan::call('coding-plan:remind-promotions', ['--dry-run' => true]);
    $dry = Artisan::output();
    $check('dry-run 输出包含窗口内活动', str_contains($dry, '[P5-4 自检] 窗口内'));
    $check('dry-run 不写入提醒流水', $reminders()->isEmpty());
    $check('dry-run 不追加 Notice', $noticeAt() === $noticeBefore);

    // —— 2) 实跑#1：公告 + 邮件 + 过期置 2 ——
    Artisan::call('coding-plan:remind-promotions');
    $run1 = Artisan::output();
    $rows = $reminders();
    $globalRow = $rows->firstWhere('user_id', 0);
    $check('实跑#1 公告流水 user_id=0（kind=ending）', $globalRow !== null && $globalRow->kind === 'ending');
    $check('实跑#1 Notice 追加活动段', str_contains($noticeAt(), '[P5-4 自检] 窗口内'));
    $check('实跑#1 订阅用户邮件流水（kind=ending）', $rows->where('user_id', $userId)->where('kind', 'ending')->count() === 1);
    $check('实跑#1 该活动行公告 1 + 邮件已入队', str_contains($run1, '窗口内 剩余 1 天，公告 1，邮件已入队'));
    $check('实跑#1 窗口外活动跳过', ! str_contains($run1, '窗口外'));
    $check('实跑#1 已到期活动被置 status=2', (int) DB::table('coding_plan_promotions')->where('id', $promoExpired)->value('status') === 2);
    $check('实跑#1 过期流水 kind=expired', $rows->where('promotion_id', $promoExpired)->where('kind', 'expired')->count() === 1);

    // —— 3) 实跑#2：防重发 ——
    $noticeAfterRun1 = $noticeAt();
    Artisan::call('coding-plan:remind-promotions');
    $rows2 = $reminders();
    $check('实跑#2 提醒流水零新增（unique 防重发）', $rows2->count() === $rows->count());
    $check('实跑#2 Notice 不变', $noticeAt() === $noticeAfterRun1);
    $check('实跑#2 已置过期活动不重复记 expired 流水', $rows2->where('promotion_id', $promoExpired)->where('kind', 'expired')->count() === 1);

    // —— 4) 删流水 → 强制重发 ——
    DB::table('coding_plan_promotion_reminders')->where('promotion_id', $promoInWindow)->delete();
    Artisan::call('coding-plan:remind-promotions');
    $reRows = $reminders()->where('promotion_id', $promoInWindow);
    $check('删流水后实跑#3 强制重发（公告+邮件各 1）', $reRows->count() === 2);
    $segCount = substr_count($noticeAt(), '[P5-4 自检] 窗口内');
    echo '   [diag] Notice 自检段数 = '.$segCount."\n";
    $check('实跑#3 Notice 再次追加（共 2 段）', $segCount === 2);

    // —— 5) 窗口外活动不受影响 ——
    $check('窗口外活动保持 status=1', (int) DB::table('coding_plan_promotions')->where('id', $promoOutside)->value('status') === 1);
    $check('窗口外活动零提醒流水', $reminders()->where('promotion_id', $promoOutside)->isEmpty());
} finally {
    DB::rollBack();
}

$check('回滚后 coding_plan_promotions 无自检残留', DB::table('coding_plan_promotions')->where('title', 'like', '[P5-4 自检]%')->count() === 0);

echo "\n{$pass} 通过，{$fail} 失败\n";
exit($fail === 0 ? 0 : 1);
