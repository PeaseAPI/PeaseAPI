<?php

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;
use App\Console\Commands\CleanLogs;
use App\Console\Commands\FixAbilities;
use App\Console\Commands\PollTasks;
use App\Console\Commands\RefreshPricing;
use App\Console\Commands\ResetSubscriptions;
use App\Console\Commands\SyncChannelCache;

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
// Frequencies mirror DEVELOPMENT.md §12.
// - High-frequency polling uses Laravel 11 sub-minute scheduling.
// - Configurable frequencies read from OptionService (DB options table)
//   with sane .env fallbacks to avoid DB dependency during bootstrap.
//
// Run via: php artisan schedule:work (dev) or system cron:
//   * * * * * cd /path && php artisan schedule:run >> /dev/null 2>&1
// ============================================

// Sync channel ability cache (default 600s, configurable via SYNC_FREQUENCY)
Schedule::command(SyncChannelCache::class)
    ->name('pease:sync-channel-cache')
    ->withoutOverlapping(10)
    ->onOneServer()
    ->everySecond((int) env('SYNC_FREQUENCY', 600));

// Poll asynchronous tasks (Midjourney / Suno / video generation) - default 5s
Schedule::command(PollTasks::class)
    ->name('pease:poll-tasks')
    ->withoutOverlapping(5)
    ->onOneServer()
    ->everySecond((int) env('TASK_POLL_FREQUENCY', 5));

// Reset subscription quotas daily at 00:00
Schedule::command(ResetSubscriptions::class)
    ->name('pease:reset-subscriptions')
    ->withoutOverlapping(30)
    ->onOneServer()
    ->dailyAt('00:00');

// Clean old logs daily (frequency can be tuned via options table)
Schedule::command(CleanLogs::class)
    ->name('pease:clean-logs')
    ->withoutOverlapping(60)
    ->onOneServer()
    ->dailyAt('03:00');

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

// --- System maintenance tasks ---

// Expire stale auth flows / sessions - hourly
Schedule::call(function () {
    \App\Models\AuthFlow::where('expires_at', '<', now())->delete();
    \App\Models\UserSession::where('expires_at', '<', now())->delete();
})->name('pease:auth-cleanup')->hourly()->onOneServer();

// System instance heartbeat - every minute
Schedule::call(function () {
    \App\Models\SystemInstance::where('node_name', config('app.name', 'pease-api'))
        ->update(['last_heartbeat' => now()->timestamp]);
})->name('pease:instance-heartbeat')->everyMinute()->onOneServer();

// Clean expired perf metrics - daily
Schedule::call(function () {
    $retentionDays = (int) env('PERF_METRICS_RETENTION_DAYS', 30);
    \App\Models\PerfMetric::where('created_at', '<', now()->subDays($retentionDays))->delete();
})->name('pease:perf-metrics-cleanup')->dailyAt('05:00')->onOneServer();