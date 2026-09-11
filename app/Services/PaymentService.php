<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\TopUp;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Stripe\Checkout\Session as StripeSession;
use Stripe\Stripe;
use Stripe\Webhook as StripeWebhook;
use Throwable;

class PaymentService
{
    public function __construct()
    {
        $this->configureStripe();
    }

    /**
     * 读取 Stripe 密钥（StripeApiSecret，别名 StripeApiKeys）
     */
    private function stripeSecret(): ?string
    {
        $secret = trim((string) OptionService::get('StripeApiSecret', OptionService::get('StripeApiKeys', '')));

        return $secret !== '' ? $secret : null;
    }

    private function configureStripe(): void
    {
        $secret = config('services.stripe.secret') ?: $this->stripeSecret();
        if ($secret) {
            Stripe::setApiKey($secret);
        }
    }

    /**
     * 创建 Stripe Checkout Session（按额度数量计价）
     *
     * @return array{url: string, trade_no: string, top_up_id: int}
     *
     * @throws Throwable 未配置 Stripe 或创建失败
     */
    public function createStripeCheckout(User $user, int $amount, float $money): array
    {
        $secret = $this->stripeSecret();
        if ($secret === null) {
            throw new \RuntimeException('Stripe 未配置，请联系管理员');
        }
        $this->configureStripe();

        $currency = strtolower(trim((string) OptionService::get('StripeCurrency', 'usd'))) ?: 'usd';
        $tradeNo = 'ST'.date('YmdHis').Str::random(8);

        $session = StripeSession::create([
            'payment_method_types' => ['card'],
            'line_items' => [[
                'price_data' => [
                    'currency' => $currency,
                    'unit_amount' => (int) round($money * 100), // 最小货币单位
                    'product_data' => [
                        'name' => '充值 '.$amount.' 额度',
                    ],
                ],
                'quantity' => 1,
            ]],
            'mode' => 'payment',
            'success_url' => config('app.url').'/wallet?stripe_success=1&trade_no='.$tradeNo,
            'cancel_url' => config('app.url').'/wallet?stripe_cancel=1',
            'client_reference_id' => (string) $user->id,
            'metadata' => [
                'trade_no' => $tradeNo,
                'user_id' => (string) $user->id,
                'amount' => (string) $amount,
            ],
        ]);

        $topUp = TopUp::create([
            'user_id' => $user->id,
            'amount' => $amount,
            'money' => $money,
            'trade_no' => $tradeNo,
            'trade_no_internal' => $tradeNo,
            'status' => 0,
            'payment_method' => 'stripe',
            'payment_id' => (string) $session->id,
            'created_at' => time(),
            'updated_at' => time(),
        ]);

        return ['url' => (string) $session->url, 'trade_no' => $tradeNo, 'top_up_id' => (int) $topUp->id];
    }

    /**
     * 处理 Stripe Webhook（验签 + 幂等履约）
     *
     * @param  string  $rawBody  原始请求体（验签必须）
     * @param  string  $signature  Stripe-Signature 请求头
     *
     * @throws Throwable 验签失败 / 密钥未配置 / 履约失败
     */
    public function handleStripeWebhook(string $rawBody, string $signature): void
    {
        $webhookSecret = trim((string) OptionService::get('StripeWebhookSecret', ''));
        if ($webhookSecret === '') {
            throw new \RuntimeException('Stripe Webhook 密钥未配置');
        }

        $event = StripeWebhook::constructEvent($rawBody, $signature, $webhookSecret);

        if (($event->type ?? '') !== 'checkout.session.completed') {
            return; // 其它事件直接确认
        }

        $session = $event->data->object ?? null;
        $tradeNo = (string) ($session->metadata->trade_no ?? '');
        if ($tradeNo === '') {
            throw new \RuntimeException('Webhook 缺少 trade_no');
        }

        $credited = false;
        DB::transaction(function () use ($tradeNo, $session, &$credited): void {
            // 条件更新作为并发锁（0=pending / 2=cancelled 均可履约），重复回调自动幂等
            $claimed = TopUp::where('trade_no', $tradeNo)
                ->whereIn('status', [0, 2])
                ->update(['status' => 1, 'updated_at' => time()]);
            if ($claimed === 0) {
                return; // 已被处理
            }

            $topUp = TopUp::where('trade_no', $tradeNo)->first();
            if (! $topUp) {
                return;
            }
            if ((string) ($session->id ?? '') !== '' && $topUp->payment_id === '') {
                $topUp->update(['payment_id' => (string) $session->id]);
            }

            $user = User::find($topUp->user_id);
            if ($user) {
                $user->increment('quota', (int) $topUp->amount);
                $credited = true;
            }
        });

        if ($credited) {
            Log::info('stripe topup completed', ['trade_no' => $tradeNo]);
        }
    }
}
