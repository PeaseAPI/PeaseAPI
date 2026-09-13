<?php

declare(strict_types=1);

/**
 * P1-9 定价源健康告警自检（非 PHPUnit，独立脚本跑完即退出）
 * 覆盖：resolveSourceStatus 连续失败升级与成功复位 / sourceHealth 汇总 /
 * 管理端页完整 render（健康卡片正反两态 + 侧边栏红点 badge）/ 事务回滚零残留
 */
require __DIR__.'/../vendor/autoload.php';

use App\Http\Controllers\AdminController;
use App\Models\CodingPlanRatioCheck;
use App\Models\User;
use App\Services\CodingPlanOfficialSourceService;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
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
$tag = 'p19selfcheck_'.random_int(100000, 999999);
$svc = app(CodingPlanOfficialSourceService::class);
const ALERT_CACHE_KEY = 'admin:cp_source_alert_badge';

DB::beginTransaction();
try {
    // 布局模板读取 auth()->user()：事务内造最小管理员并模拟登录（渲染健康卡片需要，回滚即清）
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

    // 隔离真实厂商流水（QA-25）：openai-token/google-token/siliconflow 等真实告警
    // （TLS 403 连续失败）会让 ③⑥ 的 UI 断言（告警 badge / 全部正常 / 红点消失）
    // 被环境数据污染——清掉自检 vendor 之外的 checks，事务回滚即恢复真实数据
    DB::table('coding_plan_ratio_checks')->where('vendor', '!=', $tag)->delete();

    // ① 失败升级序列：首败 FAILED → 连续第 2 次升级 ALERT
    $s1 = $svc->resolveSourceStatus($tag, CodingPlanRatioCheck::SOURCE_FAILED);
    $svc->recordCheck($tag, $s1);
    check('首次失败记 SOURCE_FAILED', $s1 === CodingPlanRatioCheck::SOURCE_FAILED);

    $s2 = $svc->resolveSourceStatus($tag, CodingPlanRatioCheck::SOURCE_FAILED);
    $svc->recordCheck($tag, $s2);
    check('连续第 2 次失败升级 SOURCE_FAILED_ALERT', $s2 === CodingPlanRatioCheck::SOURCE_FAILED_ALERT);

    $h = $svc->sourceHealth()[$tag] ?? null;
    check('sourceHealth: consecutive=2 / alerting / last_success=null',
        $h !== null && $h['consecutive_failures'] === 2 && $h['alerting'] === true && $h['last_success_at'] === null);

    // ② 第 3 次仍 ALERT 且计数累加
    $s3 = $svc->resolveSourceStatus($tag, CodingPlanRatioCheck::SOURCE_FAILED);
    $svc->recordCheck($tag, $s3);
    $h = $svc->sourceHealth()[$tag];
    check('连续第 3 次仍 ALERT 且 consecutive=3',
        $s3 === CodingPlanRatioCheck::SOURCE_FAILED_ALERT && $h['consecutive_failures'] === 3);

    // ③ 管理端页渲染（告警态）：健康卡片红 badge + 侧边栏红点
    Cache::forget(ALERT_CACHE_KEY);
    $html = (new AdminController)->codingPlan()->render();
    check('页面含官方源同步健康卡片', str_contains($html, '官方源同步健康'));
    check('卡片含连续失败告警 badge', str_contains($html, '个源连续失败告警'));
    check('自检 vendor 行入卡（table-danger）', str_contains($html, $tag) && str_contains($html, 'table-danger'));
    check('侧边栏红点 badge 渲染（健康告警红点）', str_contains($html, '官方定价源连续拉取失败（P1-9）'));

    // ④ 成功一次自然复位
    $svc->recordCheck($tag, CodingPlanRatioCheck::SOURCE_OK);
    $h = $svc->sourceHealth()[$tag];
    check('成功一次复位：consecutive=0 / 非 alerting / last_success 记录',
        $h['consecutive_failures'] === 0 && $h['alerting'] === false && is_int($h['last_success_at']));

    // ⑤ 复位后再单次失败不升级（故障窗口隔离）；OK/NONE 原样返回
    $s5 = $svc->resolveSourceStatus($tag, CodingPlanRatioCheck::SOURCE_FAILED);
    check('复位后单次失败仅 SOURCE_FAILED', $s5 === CodingPlanRatioCheck::SOURCE_FAILED);
    check('OK 状态原样返回', $svc->resolveSourceStatus($tag, CodingPlanRatioCheck::SOURCE_OK) === CodingPlanRatioCheck::SOURCE_OK);

    // ⑥ 管理端页渲染（复位态）：卡片回绿、侧边栏红点消失
    Cache::forget(ALERT_CACHE_KEY);
    $html = (new AdminController)->codingPlan()->render();
    check('复位后卡片显示「全部正常」', str_contains($html, '全部正常'));
    check('复位后侧边栏健康告警红点消失', ! str_contains($html, '官方定价源连续拉取失败（P1-9）'));
} finally {
    DB::rollBack();
    Cache::forget(ALERT_CACHE_KEY); // 自检造的告警不残留进 badge 缓存
}

$leftChecks = CodingPlanRatioCheck::query()->where('vendor', $tag)->count();
$leftUsers = User::query()->where('username', $tag.'-admin')->count();
check('回滚零残留（流水+管理员）', $leftChecks === 0 && $leftUsers === 0);

echo "\n".($fail === 0 ? '✅ P1-9 源健康告警自检全部通过' : "❌ FAILED: {$fail}")."\n";
exit($fail === 0 ? 0 : 1);
