<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Subscription;
use App\Models\SubscriptionOrder;
use App\Models\SubscriptionPlan;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * 订阅履约服务：订阅开通、支付订单履约、到期自动续订。
 *
 * 订单状态约定（subscription_orders.status，tinyint）：
 * 0=待支付 1=已支付 2=已取消。
 */
class SubscriptionService
{
    /**
     * 系统统一换算：1 单位货币对应的 quota（与充值/展示口径一致）。
     */
    public static function quotaPerUnit(): float
    {
        return (float) (OptionService::get('QuotaPerUnit', 500000) ?: 500000);
    }

    /**
     * 计划价格折算为 quota 成本（向上取整，与前端余额预估一致）。
     */
    public static function quotaCost(SubscriptionPlan $plan): int
    {
        return (int) ceil((float) $plan->price * self::quotaPerUnit());
    }

    /**
     * 计划周期结束时间（day/month/year 语义与原控制器实现一致）。
     */
    public static function calcPeriodEnd(SubscriptionPlan $plan, int $now): int
    {
        $duration = max(1, (int) $plan->duration);

        return match ($plan->duration_unit) {
            'day' => strtotime("+{$duration} days", $now),
            'year' => strtotime("+{$duration} years", $now),
            default => strtotime("+{$duration} months", $now),
        };
    }

    /**
     * 为用户开通订阅：事务内先失效旧订阅再创建新订阅。
     */
    public static function activateSubscription(User $user, SubscriptionPlan $plan, string $method, string $tradeNo = ''): Subscription
    {
        $now = time();

        return DB::transaction(function () use ($user, $plan, $method, $tradeNo, $now): Subscription {
            Subscription::where('user_id', $user->id)
                ->where('status', 1)
                ->update(['status' => 0, 'updated_at' => $now]);

            return Subscription::create([
                'user_id' => $user->id,
                'plan_id' => $plan->id,
                'status' => 1,
                'period_start' => $now,
                'period_end' => self::calcPeriodEnd($plan, $now),
                'quota' => (int) $plan->quota,
                'used_quota' => 0,
                'payment_method' => $method,
                'trade_no' => $tradeNo,
                'auto_renew' => 0,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        });
    }

    /**
     * 在线支付回调履约：幂等，将待支付订单标记为已支付并开通订阅。
     * 计划下架或用户不存在时保留待支付订单，便于人工核查。
     */
    public static function fulfillOrder(string $tradeNo): void
    {
        $order = SubscriptionOrder::where('trade_no', $tradeNo)->first();
        if (! $order || (int) $order->status === 1) {
            return;
        }

        $plan = SubscriptionPlan::find((int) $order->plan_id);
        $user = User::find((int) $order->user_id);
        if (! $plan || ! $user || (int) $plan->status !== 1) {
            Log::warning('subscription order fulfill skipped', [
                'trade_no' => $tradeNo,
                'reason' => ! $plan ? 'plan_missing' : (! $user ? 'user_missing' : 'plan_disabled'),
            ]);

            return;
        }

        DB::transaction(function () use ($order, $plan, $user): void {
            // 条件更新作为并发锁：只有第一个到达的回调能完成状态流转。
            // 已取消（status=2，如超时取消）的订单收到网关有效支付回调时同样履约：
            // 超时取消只是不再期待支付，支付事实以网关回调为准。
            $claimed = SubscriptionOrder::where('id', $order->id)
                ->whereIn('status', [0, 2])
                ->update(['status' => 1, 'paid_at' => time()]);
            if ($claimed === 0) {
                return; // 已被其他回调处理
            }

            self::activateSubscription($user, $plan, (string) $order->payment_method, (string) $order->trade_no);
        });
    }

    /**
     * 自动续订：先占用原订阅防并发重复，再原子扣减余额，开通新一期订阅。
     * 返回 false 表示余额不足/计划不可用/已被处理，调用方应将原订阅标记过期。
     */
    public static function renewSubscription(Subscription $subscription): bool
    {
        $plan = $subscription->plan;
        $user = User::find((int) $subscription->user_id);
        if (! $plan || (int) $plan->status !== 1 || ! $user) {
            return false;
        }

        $quotaCost = self::quotaCost($plan);
        $now = time();

        try {
            return (bool) DB::transaction(function () use ($subscription, $plan, $user, $quotaCost, $now): bool {
                $claimed = Subscription::where('id', $subscription->id)
                    ->where('status', 1)
                    ->update(['status' => 0, 'updated_at' => $now]);
                if ($claimed === 0) {
                    return false; // 已被其他任务处理
                }

                // 0 元计划直接续订；否则原子条件扣减，避免扣成负数
                if ($quotaCost > 0) {
                    $affected = User::where('id', $user->id)
                        ->where('quota', '>=', $quotaCost)
                        ->decrement('quota', $quotaCost);
                    if ($affected === 0) {
                        throw new \RuntimeException('余额不足');
                    }
                }

                self::activateSubscription($user, $plan, 'auto_renew', 'RENEW'.date('YmdHis', $now));

                return true;
            });
        } catch (\RuntimeException) {
            return false; // 扣费失败，事务已回滚，原订阅交由调用方标记过期
        }
    }
}
