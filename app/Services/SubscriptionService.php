<?php

namespace App\Services;

use App\Enums\PaymentStatus;
use App\Models\SubscriptionOrder;
use App\Models\SubscriptionPlan;
use App\Models\User;
use App\Models\UserSubscription;

class SubscriptionService
{
    public function subscribe(User $user, SubscriptionPlan $plan, string $paymentMethod = 'balance'): array
    {
        if ($paymentMethod === 'balance' && $user->balance < $plan->price_amount) {
            return ['success' => false, 'error' => __('Insufficient balance')];
        }

        $now = time();
        $endAt = $this->calculateEndDate($plan, $now);

        $order = SubscriptionOrder::create([
            'user_id' => $user->id,
            'plan_id' => $plan->id,
            'trade_no' => 'SUB_'.uniqid(),
            'amount' => $plan->price_amount,
            'currency' => $plan->currency ?? 'USD',
            'status' => PaymentStatus::PENDING->value,
            'payment_method' => $paymentMethod,
            'period_start' => $now,
            'period_end' => $endAt,
            'created_time' => $now,
        ]);

        if ($paymentMethod === 'balance') {
            $user->decrement('balance', (int) $plan->price_amount);
            $order->update(['status' => PaymentStatus::PAID->value, 'paid_at' => $now]);

            UserSubscription::create([
                'user_id' => $user->id,
                'plan_id' => $plan->id,
                'order_id' => $order->id,
                'start_at' => $now,
                'end_at' => $endAt,
                'status' => 'active',
                'quota_used' => 0,
                'quota_total' => $plan->total_amount,
                'created_time' => $now,
                'updated_at' => $now,
            ]);
        }

        return ['success' => true, 'order' => $order];
    }

    protected function calculateEndDate(SubscriptionPlan $plan, int $startAt): int
    {
        if ($plan->duration_unit === 'day') {
            return $startAt + $plan->duration_value * 86400;
        }
        if ($plan->duration_unit === 'month') {
            return $startAt + $plan->duration_value * 30 * 86400;
        }
        if ($plan->duration_unit === 'year') {
            return $startAt + $plan->duration_value * 365 * 86400;
        }
        if ($plan->duration_unit === 'custom') {
            return $startAt + ($plan->custom_seconds ?? 0);
        }

        return $startAt + 30 * 86400;
    }

    public function checkAndResetQuota(UserSubscription $subscription): void
    {
        $resetPeriod = $subscription->plan->quota_reset_period ?? 'never';
        if ($resetPeriod === 'never') {
            return;
        }
        $lastReset = $subscription->last_reset_at ?? $subscription->start_at;
        $now = time();
        $shouldReset = false;
        if ($resetPeriod === 'daily' && $now - $lastReset >= 86400) {
            $shouldReset = true;
        }
        if ($resetPeriod === 'monthly' && $now - $lastReset >= 30 * 86400) {
            $shouldReset = true;
        }
        if ($shouldReset) {
            $subscription->update(['quota_used' => 0, 'last_reset_at' => $now, 'quota_reset_at' => $now]);
        }
    }

    public function cancelSubscription(UserSubscription $subscription): void
    {
        $subscription->update(['status' => 'cancelled', 'cancelled_at' => time(), 'auto_renew' => false]);
    }
}
