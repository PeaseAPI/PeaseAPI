<?php

namespace App\Http\Controllers;

use App\Models\Pricing;
use App\Models\TopUp;
use App\Services\PaymentService;
use Illuminate\Http\Request;

class PaymentController extends Controller
{
    public function pricings()
    {
        return response()->json(Pricing::where('enabled', true)->orderBy('sort_order')->get());
    }

    public function createCheckout(Request $request, PaymentService $paymentService)
    {
        $request->validate(['pricing_id' => 'required|exists:pricings,id']);
        $pricing = Pricing::findOrFail($request->pricing_id);
        $result = $paymentService->createStripeCheckout($request->user(), $pricing);

        return response()->json($result);
    }

    public function stripeWebhook(Request $request, PaymentService $paymentService)
    {
        $payload = $request->all();
        try {
            $topUp = $paymentService->handleStripeWebhook($payload);

            return response()->json(['success' => true]);
        } catch (\Exception $e) {
            return response()->json(['error' => $e->getMessage()], 400);
        }
    }

    public function topUpHistory(Request $request)
    {
        $query = TopUp::where('user_id', $request->user()->id);

        return response()->json($query->orderBy('created_time', 'desc')->paginate(20));
    }
}
