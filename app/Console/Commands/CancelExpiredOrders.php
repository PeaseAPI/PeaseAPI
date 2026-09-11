<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\SubscriptionOrder;
use App\Models\TopUp;
use Illuminate\Console\Command;

/**
 * 订单超时取消任务
 *
 * 将超过支付窗口仍未支付的订单（status=0）标记为已取消（status=2）：
 * - top_ups：充值订单（TU/ST/CR/WA/WX/AL 等全部网关），同时刷新 updated_at；
 * - subscription_orders：订阅订单（SE 前缀），同时写入 cancelled_at。
 *
 * 支付窗口：config('pease-api.payment.order_timeout_minutes')，默认 1440 分钟
 * （24 小时），可用环境变量 PEASE_API_ORDER_TIMEOUT_MINUTES 覆盖；
 * 设为 0 或负数时跳过取消动作（窗口关闭）。
 *
 * 注意：取消 ≠ 拒付。若网关在取消后仍推送支付成功回调（有效签名），
 * completeTopUp / fulfillOrder 会照常履约入账——支付事实以网关回调为准。
 */
class CancelExpiredOrders extends Command
{
    protected $signature = 'orders:cancel-expired {--minutes= : 支付窗口（分钟），缺省读 pease-api.payment.order_timeout_minutes}';

    protected $description = 'Cancel pending payment orders older than the payment window';

    public function handle(): int
    {
        $minutes = (int) ($this->option('minutes') ?: config('pease-api.payment.order_timeout_minutes', 1440));
        if ($minutes <= 0) {
            $this->info('Payment window <= 0, skip cancelling orders.');

            return 0;
        }

        $cutoff = time() - $minutes * 60;

        $topUps = TopUp::where('status', 0)
            ->where('created_at', '<', $cutoff)
            ->update(['status' => 2, 'updated_at' => time()]);

        $subscriptionOrders = SubscriptionOrder::where('status', 0)
            ->where('created_at', '<', $cutoff)
            ->update(['status' => 2, 'cancelled_at' => time()]);

        $this->info(sprintf(
            'Cancelled %d top-up order(s) and %d subscription order(s) (window: %d minutes).',
            $topUps,
            $subscriptionOrders,
            $minutes
        ));

        return 0;
    }
}
