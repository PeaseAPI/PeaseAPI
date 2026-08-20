<?php

namespace App\Services;

use App\Enums\PaymentStatus;
use App\Models\Pricing;
use App\Models\TopUp;
use App\Models\User;
use Stripe\Checkout\Session as StripeSession;
use Stripe\Stripe;

class PaymentService
{
    public function __construct()
    {
        Stripe::setApiKey(config('services.stripe.secret'));
    }

    public function createStripeCheckout(User $user, Pricing $pricing): array
    {
        $session = StripeSession::create([
            'payment_method_types' => ['card'],
            'line_items' => [[
                'price' => $pricing->stripe_price_id,
                'quantity' => 1,
            ]],
            'mode' => 'payment',
            'success_url' => config('app.url').'/payment/success?session_id={CHECKOUT_SESSION_ID}',
            'cancel_url' => config('app.url').'/payment/cancel',
            'client_reference_id' => $user->id,
            'metadata' => ['pricing_id' => $pricing->id, 'user_id' => $user->id],
        ]);

        $topUp = TopUp::create([
            'user_id' => $user->id,
            'method' => 'stripe',
            'trade_no' => $session->id,
            'amount' => $pricing->price,
            'money' => $pricing->price,
            'exchange_rate' => 1,
            'status' => PaymentStatus::PENDING->value,
            'quota_amount' => $pricing->quota,
            'stripe_session_id' => $session->id,
            'created_time' => time(),
        ]);

        return ['url' => $session->url, 'top_up_id' => $topUp->id ?? null];
    }

    public function handleStripeWebhook(array $payload): TopUp
    {
        $sessionId = $payload['data']['object']['id'] ?? null;
        $topUp = TopUp::where('stripe_session_id', $sessionId)->firstOrFail();
        $topUp->update(['status' => PaymentStatus::PAID->value, 'paid_at' => time()]);
        $user = User::find($topUp->user_id);
        $user->increment('balance', $topUp->quota_amount);

        return $topUp;
    }
}
