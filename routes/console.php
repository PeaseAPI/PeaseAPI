<?php

use App\Console\Commands\CancelExpiredOrders;
use App\Console\Commands\CleanLogs;
use App\Console\Commands\FixAbilities;
use App\Console\Commands\PollTasks;
use App\Console\Commands\RefreshPricing;
use App\Console\Commands\ResetCodingPlanUsage;
use App\Console\Commands\ResetSubscriptions;
use App\Console\Commands\SyncChannelCache;
use App\Console\Commands\VerifyCodingPlanRatios;
use App\Models\AuthFlow;
use App\Models\PerfMetric;
use App\Models\SystemInstance;
use App\Models\UserSession;
use Illuminate\Console\Scheduling\Event;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;
use Illuminate\Support\Facades\Schema;

/*
|--------------------------------------------------------------------------
| Console Routes
|--------------------------------------------------------------------------
|
| This file is where you may define all of your Closure based console
| commands and scheduled tasks for the Pease API application.
|
*/

// ============================================
// Interactive / Manual Commands
// ============================================

Artisan::command('pease:info', function () {
    $this->comment('Pease API - PHP 8.3 + Laravel 11 AI Gateway');
    $this->info('Source: QuantumNous/new-api (Go/Gin) -> PHP rewrite');
})->purpose('Display Pease API project information');

// ============================================
// Scheduled Tasks (mirrors Go ticker/cron in new-api)
// ============================================
// - High-frequency tasks use sub-minute scheduling (named everyXSeconds()).
// - Frequencies (seconds) come from config/pease-api.php with env overrides
//   (PEASE_API_SYNC_FREQUENCY, PEASE_API_TASK_POLL_FREQUENCY); values map to
//   the named 1-30s primitives, or cron whole-minutes (rounded up) for >= 60s.
//
// Run via: php artisan schedule:work (dev) or system cron:
//   * * * * * cd /path && php artisan schedule:run >> /dev/null 2>&1
// ============================================

// Map a desired frequency (seconds) onto the scheduler primitives available in
// this framework version: named everyXSeconds() for sub-minute values (1-30s,
// rounded up to the next safer step), cron whole-minutes for >= 60s. There is
// no everySeconds($n) API, and extra args to everySecond() are silently ignored.
$frequency = function (Event $event, int $seconds): Event {
    $seconds = max(1, $seconds);

    return match (true) {
        $seconds <= 1 => $event->everySecond(),
        $seconds <= 2 => $event->everyTwoSeconds(),
        $seconds <= 5 => $event->everyFiveSeconds(),
        $seconds <= 10 => $event->everyTenSeconds(),
        $seconds <= 15 => $event->everyFifteenSeconds(),
        $seconds <= 20 => $event->everyTwentySeconds(),
        $seconds <= 30 => $event->everyThirtySeconds(),
        default => $event->cron('*/'.max(1, (int) ceil($seconds / 60)).' * * * *'),
    };
};

// Sync channel ability cache (config pease-api.sync_frequency, seconds; default 60)
$frequency(
    Schedule::command(SyncChannelCache::class)
        ->name('pease:sync-channel-cache')
        ->withoutOverlapping(10)
        ->onOneServer(),
    (int) config('pease-api.sync_frequency', 60)
);

// Poll asynchronous tasks (Midjourney / Suno / video generation)
// (config pease-api.task_poll_frequency, seconds; default 5)
$frequency(
    Schedule::command(PollTasks::class)
        ->name('pease:poll-tasks')
        ->withoutOverlapping(5)
        ->onOneServer(),
    (int) config('pease-api.task_poll_frequency', 5)
);

// Reset subscription quotas daily at 00:00
Schedule::command(ResetSubscriptions::class)
    ->name('pease:reset-subscriptions')
    ->withoutOverlapping(30)
    ->onOneServer()
    ->dailyAt('00:00');

// Reset Coding Plan usage windows every 5 minutes & disable expired accounts hourly
Schedule::command(ResetCodingPlanUsage::class)
    ->name('pease:reset-coding-plan')
    ->withoutOverlapping(5)
    ->onOneServer()
    ->everyFiveMinutes();

// Clean old logs daily (frequency can be tuned via options table)
Schedule::command(CleanLogs::class)
    ->name('pease:clean-logs')
    ->withoutOverlapping(60)
    ->onOneServer()
    ->dailyAt('03:00');

// Cancel stale pending payment orders (top-up & subscription) - every 10 minutes
// Payment window: config('pease-api.payment.order_timeout_minutes'), default 1440 (24h)
Schedule::command(CancelExpiredOrders::class)
    ->name('pease:cancel-expired-orders')
    ->withoutOverlapping(5)
    ->onOneServer()
    ->everyTenMinutes();

// Fix channel ability inconsistencies hourly
Schedule::command(FixAbilities::class)
    ->name('pease:fix-abilities')
    ->withoutOverlapping(10)
    ->onOneServer()
    ->hourly();

// Refresh pricing table daily
Schedule::command(RefreshPricing::class)
    ->name('pease:refresh-pricing')
    ->withoutOverlapping(10)
    ->onOneServer()
    ->dailyAt('04:00');

// Verify Coding Plan deduction ratios every 6 hours (stale detection + optional
// pricing source diff). Changes are recorded to coding_plan_ratio_checks only -
// applying them stays a manual admin decision. Schema-guarded like task polling
// so fresh installs without the coding plan tables simply skip.
if (Schema::hasTable('coding_plan_model_ratios')) {
    Schedule::command(VerifyCodingPlanRatios::class)
        ->name('pease:verify-coding-plan-ratios')
        ->withoutOverlapping(10)
        ->onOneServer()
        ->everySixHours();
}

// --- System maintenance tasks ---

// Expire stale auth flows / sessions - hourly
Schedule::call(function () {
    AuthFlow::where('expires_at', '<', now())->delete();
    UserSession::where('expires_at', '<', now())->delete();
})->name('pease:auth-cleanup')->hourly()->onOneServer();

// System instance heartbeat - every minute
// （last_heartbeat 为 timestamp 列，写 Carbon；写 int 时间戳会存成 0000-00-00 或严格模式报错）
Schedule::call(function () {
    SystemInstance::where('node_name', config('app.name', 'pease-api'))
        ->update(['last_heartbeat' => now()]);
})->name('pease:instance-heartbeat')->everyMinute()->onOneServer();

// Clean expired perf metrics - daily（perf_metrics 无 created_at，按 bucket_ts 整型时间戳过滤）
Schedule::call(function () {
    $retentionDays = max(1, (int) config('pease-api.perf_metrics_retention_days', 30));
    PerfMetric::where('bucket_ts', '<', now()->subDays($retentionDays)->getTimestamp())->delete();
})->name('pease:perf-metrics-cleanup')->dailyAt('05:00')->onOneServer();
