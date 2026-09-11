<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Subscription;
use App\Services\SubscriptionService;
use Illuminate\Console\Command;

/**
 * 订阅过期处理任务
 *
 * 逐条处理 period_end 已过期的活跃订阅（status=1）：
 * - auto_renew=1 且计划仍在售：原子扣费自动续订（余额不足则过期）；
 * - 其余：标记失效（status → 0）。
 */
class ResetSubscriptions extends Command
{
    protected $signature = 'subscription:reset';

    protected $description = 'Auto-renew or expire overdue subscriptions';

    public function handle(): int
    {
        $this->info('Checking expired subscriptions...');

        $now = time();
        $expired = Subscription::with('plan')
            ->where('status', 1)
            ->where('period_end', '>', 0)
            ->where('period_end', '<', $now)
            ->get();

        $renewed = 0;
        $marked = 0;
        foreach ($expired as $subscription) {
            if ((int) $subscription->auto_renew === 1 && SubscriptionService::renewSubscription($subscription)) {
                $renewed++;

                continue;
            }

            // 条件更新与并发续订互斥（续订事务内已把原订阅置 0）
            $marked += Subscription::where('id', $subscription->id)
                ->where('status', 1)
                ->update(['status' => 0, 'updated_at' => $now]);
        }

        $this->info("Auto-renewed {$renewed}, expired {$marked} subscriptions.");

        return 0;
    }
}
