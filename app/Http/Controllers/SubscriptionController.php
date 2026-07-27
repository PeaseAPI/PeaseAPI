<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\Subscription;
use App\Models\SubscriptionPlan;
use App\Models\User;
use App\Services\OptionService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class SubscriptionController extends Controller
{
    /**
     * 订阅计划列表（公开）
     * GET /api/subscription/plans
     */
    public function plans(): JsonResponse
    {
        $plans = SubscriptionPlan::getActivePlans();

        return $this->success($plans);
    }

    /**
     * 用户订阅信息
     * GET /api/subscription/self
     */
    public function mySubscription(Request $request): JsonResponse
    {
        $user = $request->user();
        $subscription = Subscription::with('plan')
            ->where('user_id', $user->id)
            ->where('status', 1)
            ->first();

        return $this->success([
            'subscription' => $subscription,
            'enabled' => (bool) OptionService::get('SubscriptionEnabled', false),
        ]);
    }

    /**
     * 更新订阅偏好
     * PUT /api/subscription/self/preference
     */
    public function updatePreference(Request $request): JsonResponse
    {
        $user = $request->user();
        $autoRenew = (int) $request->input('auto_renew', 0);

        $subscription = Subscription::where('user_id', $user->id)
            ->where('status', 1)
            ->first();
        if (! $subscription) {
            return $this->error('无有效订阅');
        }
        $subscription->auto_renew = $autoRenew;
        $subscription->updated_at = time();
        $subscription->save();

        return $this->success();
    }

    /**
     * 余额支付订阅
     * POST /api/subscription/balance/pay
     */
    public function payWithBalance(Request $request): JsonResponse
    {
        $user = $request->user();
        $planId = (int) $request->input('plan_id', 0);
        $plan = SubscriptionPlan::findActiveById($planId);
        if (! $plan) {
            return $this->error('订阅计划不存在');
        }

        $price = (float) $plan->price;
        $quotaCost = (int) ($price * 500000); // 1元 = 500000 quota（示例换算）
        if ($user->quota < $quotaCost) {
            return $this->error('余额不足');
        }

        DB::transaction(function () use ($user, $plan, $quotaCost): void {
            $user->quota -= $quotaCost;
            $user->save();
            $this->createSubscription($user, $plan, 'balance');
        });

        return $this->success();
    }

    /**
     * 易支付下单
     * POST /api/subscription/epay/pay
     */
    public function payWithEpay(Request $request): JsonResponse
    {
        $plan = $this->resolvePlan($request);
        if (! $plan instanceof SubscriptionPlan) {
            return $this->error('订阅计划不存在');
        }

        $tradeNo = 'SE' . date('YmdHis') . Str::random(8);

        return $this->success([
            'trade_no' => $tradeNo,
            'money' => (float) $plan->price,
            'plan_id' => $plan->id,
            'plan_name' => $plan->name,
        ]);
    }

    /**
     * Stripe 支付订阅
     * POST /api/subscription/stripe/pay
     */
    public function payWithStripe(Request $request): JsonResponse
    {
        $plan = $this->resolvePlan($request);
        if (! $plan instanceof SubscriptionPlan) {
            return $this->error('订阅计划不存在');
        }

        $tradeNo = 'SS' . date('YmdHis') . Str::random(8);

        return $this->success([
            'trade_no' => $tradeNo,
            'client_secret' => '',
            'amount' => (int) ((float) $plan->price * 100),
            'currency' => strtolower($plan->currency ?: 'usd'),
        ]);
    }

    /**
     * Creem 支付订阅
     * POST /api/subscription/creem/pay
     */
    public function payWithCreem(Request $request): JsonResponse
    {
        $plan = $this->resolvePlan($request);
        if (! $plan instanceof SubscriptionPlan) {
            return $this->error('订阅计划不存在');
        }

        $tradeNo = 'SC' . date('YmdHis') . Str::random(8);

        return $this->success(['trade_no' => $tradeNo]);
    }

    /**
     * Waffo-Pancake 支付订阅
     * POST /api/subscription/waffo-pancake/pay
     */
    public function payWithWaffoPancake(Request $request): JsonResponse
    {
        $plan = $this->resolvePlan($request);
        if (! $plan instanceof SubscriptionPlan) {
            return $this->error('订阅计划不存在');
        }

        $tradeNo = 'SW' . date('YmdHis') . Str::random(8);

        return $this->success(['trade_no' => $tradeNo]);
    }

    /**
     * Dashboard 订阅信息（OpenAI 兼容）
     * GET /dashboard/billing/subscription
     */
    public function dashboard(Request $request): JsonResponse
    {
        $user = $request->user();
        $subscription = Subscription::with('plan')
            ->where('user_id', $user->id)
            ->where('status', 1)
            ->first();

        $hardLimitUsd = $subscription
            ? round((float) ($subscription->quota / 500000), 2)
            : round((float) ($user->quota / 500000), 2);

        return response()->json([
            'object' => 'billing_subscription',
            'has_payment_method' => true,
            'soft_limit_usd' => $hardLimitUsd,
            'hard_limit_usd' => $hardLimitUsd,
            'system_hard_limit_usd' => $hardLimitUsd,
            'access_until' => $subscription ? (int) $subscription->period_end : 0,
        ]);
    }

    // ====== 管理员接口 ======

    /**
     * 管理员计划列表
     * GET /api/subscription/admin/plans
     */
    public function allPlans(Request $request): JsonResponse
    {
        $this->requireAdmin($request);
        $query = SubscriptionPlan::query();
        if (SubscriptionPlan::hasColumnSafe('sort')) {
            $query->orderBy('sort');
        }
        $query->orderBy('id');
        /** @var LengthAwarePaginator $plans */
        $plans = $query->paginate((int) $request->input('per_page', 20));

        return $this->paginate($plans);
    }

    /**
     * 创建计划
     * POST /api/subscription/admin/plans
     */
    public function createPlan(Request $request): JsonResponse
    {
        $this->requireAdmin($request);
        $data = $this->validatePlan($request);
        $data['created_at'] = time();
        $data['updated_at'] = time();
        $plan = SubscriptionPlan::create($data);

        return $this->success($plan);
    }

    /**
     * 更新计划
     * PUT /api/subscription/admin/plans/{id}
     */
    public function updatePlan(Request $request, int $id): JsonResponse
    {
        $this->requireAdmin($request);
        $plan = SubscriptionPlan::find($id);
        if (! $plan) {
            return $this->error('计划不存在');
        }
        $data = $this->validatePlan($request);
        $data['updated_at'] = time();
        $plan->update($data);

        return $this->success($plan);
    }

    /**
     * 更新计划状态
     * PATCH /api/subscription/admin/plans/{id}
     */
    public function togglePlan(Request $request, int $id): JsonResponse
    {
        $this->requireAdmin($request);
        $plan = SubscriptionPlan::find($id);
        if (! $plan) {
            return $this->error('计划不存在');
        }
        $plan->status = (int) $request->input('status', $plan->status);
        $plan->updated_at = time();
        $plan->save();

        return $this->success();
    }

    /**
     * 绑定订阅（管理员）
     * POST /api/subscription/admin/bind
     */
    public function bindSubscription(Request $request): JsonResponse
    {
        $this->requireAdmin($request);
        $userId = (int) $request->input('user_id', 0);
        $planId = (int) $request->input('plan_id', 0);
        $user = User::find($userId);
        $plan = SubscriptionPlan::find($planId);
        if (! $user || ! $plan) {
            return $this->error('用户或计划不存在');
        }

        $this->createSubscription($user, $plan, 'admin');

        return $this->success();
    }

    /**
     * 重置计划下所有订阅
     * POST /api/subscription/admin/plans/{id}/subscriptions/reset
     */
    public function resetPlanSubscriptions(Request $request, int $id): JsonResponse
    {
        $this->requireAdmin($request);
        $plan = SubscriptionPlan::find($id);
        if (! $plan) {
            return $this->error('计划不存在');
        }
        $count = Subscription::where('plan_id', $id)->where('status', 1)->update([
            'used_quota' => 0,
            'quota' => $plan->quota,
            'updated_at' => time(),
        ]);

        return $this->success(['count' => $count]);
    }

    /**
     * 用户订阅列表（管理员）
     * GET /api/subscription/admin/users/{id}/subscriptions
     */
    public function userSubscriptions(Request $request, int $id): JsonResponse
    {
        $this->requireAdmin($request);
        /** @var LengthAwarePaginator $subscriptions */
        $subscriptions = Subscription::with('plan')->where('user_id', $id)
            ->orderByDesc('id')->paginate((int) $request->input('per_page', 20));

        return $this->paginate($subscriptions);
    }

    /**
     * 创建用户订阅（管理员）
     * POST /api/subscription/admin/users/{id}/subscriptions
     */
    public function createUserSubscription(Request $request, int $id): JsonResponse
    {
        $this->requireAdmin($request);
        $user = User::find($id);
        $planId = (int) $request->input('plan_id', 0);
        $plan = SubscriptionPlan::find($planId);
        if (! $user || ! $plan) {
            return $this->error('用户或计划不存在');
        }
        $this->createSubscription($user, $plan, 'admin');

        return $this->success();
    }

    /**
     * 重置用户订阅（管理员）
     * POST /api/subscription/admin/users/{id}/subscriptions/reset
     */
    public function resetUserSubscription(Request $request, int $id): JsonResponse
    {
        $this->requireAdmin($request);
        $subs = Subscription::where('user_id', $id)->where('status', 1)->get();
        foreach ($subs as $sub) {
            $plan = SubscriptionPlan::find($sub->plan_id);
            if ($plan) {
                $sub->used_quota = 0;
                $sub->quota = $plan->quota;
                $sub->updated_at = time();
                $sub->save();
            }
        }

        return $this->success(['count' => $subs->count()]);
    }

    /**
     * 失效订阅（管理员）
     * POST /api/subscription/admin/user_subscriptions/{id}/invalidate
     */
    public function invalidateSubscription(Request $request, int $id): JsonResponse
    {
        $this->requireAdmin($request);
        $sub = Subscription::find($id);
        if (! $sub) {
            return $this->error('订阅不存在');
        }
        $sub->status = 0;
        $sub->updated_at = time();
        $sub->save();

        return $this->success();
    }

    /**
     * 删除订阅（管理员）
     * DELETE /api/subscription/admin/user_subscriptions/{id}
     */
    public function deleteSubscription(Request $request, int $id): JsonResponse
    {
        $this->requireAdmin($request);
        Subscription::destroy($id);

        return $this->success();
    }

    // ====== 辅助方法 ======

    private function resolvePlan(Request $request): ?SubscriptionPlan
    {
        $planId = (int) $request->input('plan_id', 0);

        return SubscriptionPlan::findActiveById($planId);
    }

    private function createSubscription(User $user, SubscriptionPlan $plan, string $method): Subscription
    {
        $now = time();
        $periodEnd = $this->calcPeriodEnd($plan, $now);

        return DB::transaction(function () use ($user, $plan, $method, $now, $periodEnd): Subscription {
            // 失效旧订阅
            Subscription::where('user_id', $user->id)->where('status', 1)->update([
                'status' => 0,
                'updated_at' => $now,
            ]);

            return Subscription::create([
                'user_id' => $user->id,
                'plan_id' => $plan->id,
                'status' => 1,
                'period_start' => $now,
                'period_end' => $periodEnd,
                'quota' => $plan->quota,
                'used_quota' => 0,
                'payment_method' => $method,
                'trade_no' => '',
                'auto_renew' => 0,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        });
    }

    private function calcPeriodEnd(SubscriptionPlan $plan, int $now): int
    {
        $duration = max(1, (int) $plan->duration);
        return match ($plan->duration_unit) {
            'day' => strtotime("+{$duration} days", $now),
            'year' => strtotime("+{$duration} years", $now),
            default => strtotime("+{$duration} months", $now),
        };
    }

    private function validatePlan(Request $request): array
    {
        return $request->validate([
            'name' => 'required|string|max:100',
            'description' => 'nullable|string|max:500',
            'price' => 'required|numeric|min:0',
            'currency' => 'nullable|string|max:10',
            'quota' => 'required|integer|min:0',
            'duration' => 'required|integer|min:1',
            'duration_unit' => 'required|in:day,month,year',
            'reset_period' => 'nullable|in:none,daily,weekly,monthly',
            'sort' => 'nullable|integer|min:0',
            'status' => 'nullable|integer|in:0,1',
        ]);
    }

    /**
     * 管理员权限校验（双保险，路由已通过 AdminAuth 中间件）
     */
    private function requireAdmin(Request $request): void
    {
        $user = $request->user();
        if (! $user || (int) $user->role < 10) {
            abort(403, '无权限访问');
        }
    }
}
