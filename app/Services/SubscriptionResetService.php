<?php

namespace App\Services;

use App\Models\Subscription;
use App\Models\SubscriptionPlan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class SubscriptionResetService
{
    /**
     * 重置过期订阅
     */
    public function resetExpiredSubscriptions(): int
    {
        $now = time();
        $count = 0;

        Subscription::where('status', 1)
            ->where('period_end', '<', $now)
            ->chunkById(100, function ($subscriptions) use (&$count) {
                foreach ($subscriptions as $subscription) {
                    $this->expireSubscription($subscription);
                    $count++;
                }
            });

        Log::info('subscription reset expired', ['count' => $count]);

        return $count;
    }

    /**
     * 重置周期配额
     */
    public function resetPeriodQuota(): int
    {
        $now = time();
        $count = 0;

        // 按计划的重置周期处理
        $plans = SubscriptionPlan::whereIn('reset_period', ['daily', 'weekly', 'monthly'])->get();
        foreach ($plans as $plan) {
            $subscriptions = Subscription::where('plan_id', $plan->id)
                ->where('status', 1)
                ->where('period_end', '>=', $now)
                ->get();

            foreach ($subscriptions as $subscription) {
                if ($this->shouldReset($subscription, $plan)) {
                    $this->resetQuota($subscription, $plan);
                    $count++;
                }
            }
        }

        Log::info('subscription period quota reset', ['count' => $count]);

        return $count;
    }

    private function expireSubscription(Subscription $subscription): void
    {
        DB::transaction(function () use ($subscription) {
            $subscription->status = 2;
            $subscription->updated_at = time();
            $subscription->save();
        });
    }

    private function shouldReset(Subscription $subscription, SubscriptionPlan $plan): bool
    {
        $now = time();
        $lastReset = $subscription->updated_at;
        $diff = $now - $lastReset;

        return match ($plan->reset_period) {
            'daily' => $diff >= 86400,
            'weekly' => $diff >= 604800,
            'monthly' => $diff >= 2592000,
            default => false,
        };
    }

    private function resetQuota(Subscription $subscription, SubscriptionPlan $plan): void
    {
        DB::transaction(function () use ($subscription, $plan) {
            $subscription->used_quota = 0;
            $subscription->quota = $plan->quota;
            $subscription->updated_at = time();
            $subscription->save();
        });
    }
}
