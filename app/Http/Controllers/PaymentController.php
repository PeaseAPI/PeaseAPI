<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\Pricing;
use App\Models\TopUp;
use App\Services\OptionService;
use App\Services\PaymentService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Throwable;

class PaymentController extends Controller
{
    /**
     * 模型价格表（pricings 表：model_name+group 主键，RefreshPricing 维护）
     * GET /web-api/pricings
     *
     * 注：历史实现误将本表当作充值套餐（where enabled），导致 SQL 500（无 enabled 列）。
     */
    public function pricings(): JsonResponse
    {
        $rows = Pricing::query()
            ->orderBy('sort_order')
            ->orderBy('model_name')
            ->get();

        return response()->json($rows);
    }

    /**
     * Stripe Checkout 下单（按额度数量计价：money = amount × Price）
     * POST /web-api/payment/checkout
     */
    public function createCheckout(Request $request, PaymentService $paymentService): JsonResponse
    {
        $validated = $request->validate([
            'amount' => 'required|integer|min:1',
        ]);

        $amount = (int) $validated['amount'];
        $price = (float) OptionService::get('Price', 0.01);
        $money = round($amount * $price, 2);

        try {
            $result = $paymentService->createStripeCheckout($request->user(), $amount, $money);
        } catch (Throwable $e) {
            return $this->error($e->getMessage(), 400);
        }

        return response()->json($result);
    }

    /**
     * Stripe Webhook（验签 + 幂等入账）
     * POST /api/stripe/webhook
     */
    public function stripeWebhook(Request $request, PaymentService $paymentService): JsonResponse
    {
        try {
            $paymentService->handleStripeWebhook(
                $request->getContent(),
                (string) $request->header('Stripe-Signature', '')
            );
        } catch (Throwable $e) {
            return response()->json(['error' => $e->getMessage()], 400);
        }

        return response()->json(['success' => true]);
    }

    /**
     * 充值订单历史
     * GET /web-api/payment/history
     */
    public function topUpHistory(Request $request): JsonResponse
    {
        $query = TopUp::where('user_id', $request->user()->id);

        return response()->json($query->orderBy('created_at', 'desc')->paginate(20));
    }
}
