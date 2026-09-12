<?php

declare(strict_types=1);

/**
 * P9-4 管理端可观测自检（非 PHPUnit，独立脚本跑完即退出）
 * 覆盖：AdminController::codingPlan() 视图渲染 —— 账号池概览聚合 / 账号+渠道冷却倒计时 /
 * 跨源 failover 路由决策统计（meta.route 解析、落选原因与候选成本展示）/ 事务回滚零残留
 */
require __DIR__.'/../vendor/autoload.php';

use App\Http\Controllers\AdminController;
use App\Models\Channel;
use App\Models\CodingPlanAccount;
use App\Models\CodingPlanUsageLog;
use App\Models\User;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

$fail = 0;
function check(string $name, bool $cond): void
{
    global $fail;
    echo ($cond ? '  ✓ ' : '  ✗ ').$name."\n";
    if (! $cond) {
        $fail++;
    }
}

$app = require __DIR__.'/../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

$now = time();
$tag = 'p94selfcheck_'.random_int(100000, 999999);

DB::beginTransaction();
try {
    // 布局模板读取 auth()->user()，事务内造最小管理员并模拟登录（回滚即清）
    $admin = User::query()->create([
        'username' => $tag.'-admin',
        'password' => bcrypt('selfcheck'),
        'aff_code' => substr($tag, 0, 32),
        'email' => $tag.'@selfcheck.local',
        'status' => 1,
        'role' => 100,
        'group' => 'default',
        'created_at' => $now,
    ]);
    Auth::login($admin);
    // ① 造账号池：同 vendor 三种状态（可用/冷却+耗尽/停用）
    $coolUntil = $now + 3600;
    $accAvail = CodingPlanAccount::query()->create([
        'vendor' => $tag, 'billing_mode' => 1, 'account_name' => $tag.'-avail',
        'api_key' => 'sk-test', 'status' => 1, 'priority' => 0,
        'quota_5h' => 100, 'used_5h' => 0, 'created_at' => $now, 'updated_at' => $now,
    ]);
    CodingPlanAccount::query()->create([
        'vendor' => $tag, 'billing_mode' => 1, 'account_name' => $tag.'-cool',
        'api_key' => 'sk-test', 'status' => 2, 'priority' => 0,
        'cooldown_until' => $coolUntil, 'created_at' => $now, 'updated_at' => $now,
    ]);
    CodingPlanAccount::query()->create([
        'vendor' => $tag, 'billing_mode' => 1, 'account_name' => $tag.'-out',
        'api_key' => 'sk-test', 'status' => 0, 'priority' => 0,
        'created_at' => $now, 'updated_at' => $now,
    ]);

    // ② 渠道冷却：取现有渠道事务内打标（库中存在才断言该分支）
    $channel = Channel::query()->first();
    $origCooldown = $channel?->cooldown_until;
    $channelTouched = false;
    if ($channel !== null) {
        $channel->cooldown_until = $coolUntil;
        $channel->save();
        $channelTouched = true;
    }

    // ③ 造 failover 流水 2 条：cost_first（候选含成本）+ static（候选无成本）
    $routeA = [
        'reason' => 'cost_failover', 'strategy' => 'cost_first',
        'failed_channel_id' => 901, 'final_channel_id' => 902, 'decided_at' => $now,
        'attempted' => [
            ['channel_id' => 901, 'vendor' => 'uniconn', 'cost_per_1k' => null, 'reason' => 'pool_exhausted', 'error' => 'account pool exhausted'],
            ['channel_id' => 903, 'vendor' => 'zhipu', 'cost_per_1k' => 0.02, 'reason' => 'pool_exhausted', 'error' => 'account pool exhausted'],
        ],
    ];
    $routeB = [
        'reason' => 'cost_failover', 'strategy' => 'static',
        'failed_channel_id' => 901, 'final_channel_id' => 902, 'decided_at' => $now,
        'attempted' => [
            ['channel_id' => 901, 'vendor' => null, 'cost_per_1k' => null, 'reason' => 'apply_failed', 'error' => 'no mapping'],
        ],
    ];
    foreach ([$routeA, $routeB] as $i => $route) {
        CodingPlanUsageLog::query()->create([
            'account_id' => $accAvail->id, 'vendor' => $tag, 'user_id' => 0,
            'channel_id' => 902, 'model' => 'gpt-4o', 'count' => 1, 'units' => 1,
            'credits' => 0, 'prompt_tokens' => 0, 'completion_tokens' => 0, 'total_tokens' => 0,
            'request_id' => $tag.'-'.$i, 'success' => true, 'error' => null,
            'meta' => ['route' => $route], 'created_at' => $now,
        ]);
    }

    // ④ 调用控制器并完整渲染（blade 编译 + HTML 输出）
    $response = app(AdminController::class)->codingPlan();
    $data = $response->getData();
    $html = $response->render();

    check('视图完整渲染（blade 编译无异常）', is_string($html) && $html !== '');
    check('账号池概览含自检 vendor 行', str_contains($html, $tag));
    check('failover 统计计数 = 2', $data['routeStats']['failover_count'] === 2);
    check('落选原因分布 pool_exhausted=2 / apply_failed=1',
        ($data['routeStats']['by_reason']['pool_exhausted'] ?? 0) === 2
        && ($data['routeStats']['by_reason']['apply_failed'] ?? 0) === 1);
    check('承接 vendor 分布 selfcheck=2', ($data['routeStats']['by_final_vendor'][$tag] ?? 0) === 2);
    check('明细 recent = 2 条', count($data['routeStats']['recent']) === 2);
    check('成本明细展示（¥0.0200/1k）', str_contains($html, '¥'.number_format(0.02, 4).'/1k'));
    check('static 无成本候选显示成本占位', str_contains($html, '成本—'));
    check('账号冷却倒计时元素（data-deadline）', substr_count($html, 'data-deadline') >= 1);

    $pool = $data['poolOverview']->firstWhere('vendor', $tag);
    check('池聚合：total=3 / cooling=1 / exhausted=1 / disabled=1 / available=1',
        $pool !== null && (int) $pool->total === 3 && (int) $pool->cooling === 1
        && (int) $pool->exhausted === 1 && (int) $pool->disabled === 1 && (int) $pool->available === 1);

    if ($channelTouched) {
        check('渠道冷却列表命中打标渠道', $data['coolingChannels']->contains(
            fn ($c) => (int) $c->id === (int) $channel->id && (int) $c->cooldown_until === $coolUntil
        ));
    }
} finally {
    DB::rollBack();
}

// ⑤ 回滚零残留
check('usage_logs 无自检残留', CodingPlanUsageLog::query()->where('vendor', $tag)->count() === 0);
check('accounts 无自检残留', CodingPlanAccount::query()->where('vendor', $tag)->count() === 0);
if (isset($channel, $origCooldown) && $channel !== null) {
    $channel->refresh();
    check('渠道 cooldown 恢复原值', (int) $channel->cooldown_until === (int) $origCooldown);
}

echo $fail === 0 ? "\n✅ P9-4 可观测自检全部通过\n" : "\n❌ 失败 {$fail} 项\n";
exit($fail === 0 ? 0 : 1);
