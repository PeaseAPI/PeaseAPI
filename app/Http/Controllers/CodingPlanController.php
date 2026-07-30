<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\CodingPlanAccount;
use App\Models\CodingPlanUsageLog;
use App\Models\SubscriptionPlan;
use App\Services\CodingPlanPoolService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Coding Plan 账号池管理控制器
 *
 * 提供账号 CRUD、套餐（SubscriptionPlan）关联、供应商概览、
 * 手动重置窗口、使用流水查询等接口。
 *
 * 注意：方法名与 routes/api.php 中注册的路由动作一一对应。
 */
class CodingPlanController extends Controller
{
    public function __construct(
        private readonly CodingPlanPoolService $poolService,
    ) {}

    /**
     * 账号列表（支持按供应商/状态/关键字筛选 + 分页）
     * GET /coding_plan/accounts
     */
    public function accounts(Request $request): JsonResponse
    {
        $query = CodingPlanAccount::query()
            ->when($request->input('vendor'), fn ($q, $v) => $q->where('vendor', $v))
            ->when($request->input('status') !== null, fn ($q) => $q->where('status', (int) $request->input('status')))
            ->when($request->input('keyword'), fn ($q, $v) => $q->where('account_name', 'like', "%{$v}%"))
            ->orderBy('vendor')
            ->orderBy('priority')
            ->orderBy('id');

        $perPage = (int) $request->input('per_page', 20);

        return $this->paginate($query->paginate($perPage));
    }

    /**
     * 创建账号
     * POST /coding_plan/accounts
     */
    public function storeAccount(Request $request): JsonResponse
    {
        $data = $request->validate([
            'vendor' => ['required', 'string', 'max:64'],
            'account_name' => ['required', 'string', 'max:128'],
            'channel_id' => ['nullable', 'integer', 'min:0'],
            'api_key' => ['nullable', 'string'],
            'base_url' => ['nullable', 'string', 'max:255'],
            'quota_5h' => ['nullable', 'integer', 'min:0'],
            'quota_weekly' => ['nullable', 'integer', 'min:0'],
            'quota_monthly' => ['nullable', 'integer', 'min:0'],
            'used_5h' => ['nullable', 'integer', 'min:0'],
            'used_weekly' => ['nullable', 'integer', 'min:0'],
            'used_monthly' => ['nullable', 'integer', 'min:0'],
            // 统一为百分比整数 0-100
            'monthly_usage_threshold' => ['nullable', 'integer', 'min:0', 'max:100'],
            'priority' => ['nullable', 'integer', 'min:0'],
            // 到期时间支持 Unix 秒或日期字符串
            'expires_at' => ['nullable'],
            'status' => ['nullable', 'integer', 'in:0,1,2'],
            'remark' => ['nullable', 'string', 'max:500'],
        ]);

        $now = time();
        $data['quota_5h'] ??= 0;
        $data['quota_weekly'] ??= 0;
        $data['quota_monthly'] ??= 0;
        $data['used_5h'] ??= 0;
        $data['used_weekly'] ??= 0;
        $data['used_monthly'] ??= 0;
        $data['monthly_usage_threshold'] ??= 80;
        $data['priority'] ??= 100;
        $data['status'] ??= CodingPlanAccount::STATUS_ENABLED;
        $data['channel_id'] ??= 0;

        // 到期时间归一化为 Unix 秒
        $data['expires_at'] = $this->normalizeExpiresAt($data['expires_at'] ?? null);

        // 初始化滚动窗口重置时间
        if ($data['quota_5h'] > 0) {
            $data['reset_5h_at'] = $now + 5 * 3600;
        }
        if ($data['quota_weekly'] > 0) {
            $data['reset_weekly_at'] = $now + 7 * 24 * 3600;
        }
        if ($data['quota_monthly'] > 0) {
            $data['reset_monthly_at'] = $now + 30 * 24 * 3600;
        }

        // API Key 加密存储（base64 可逆，生产建议替换为 Laravel Encrypter）
        if (! empty($data['api_key'])) {
            $data['api_key'] = base64_encode((string) $data['api_key']);
        }

        $data['created_at'] = $now;
        $data['updated_at'] = $now;

        $account = CodingPlanAccount::create($data);

        return $this->success($account, '账号已创建');
    }

    /**
     * 更新账号
     * PUT /coding_plan/accounts/{id}
     */
    public function updateAccount(Request $request, int $id): JsonResponse
    {
        $account = CodingPlanAccount::find($id);
        if (! $account) {
            return $this->error('账号不存在', 404);
        }

        $data = $request->validate([
            'vendor' => ['sometimes', 'string', 'max:64'],
            'account_name' => ['sometimes', 'string', 'max:128'],
            'channel_id' => ['nullable', 'integer', 'min:0'],
            'api_key' => ['nullable', 'string'],
            'base_url' => ['nullable', 'string', 'max:255'],
            'quota_5h' => ['sometimes', 'integer', 'min:0'],
            'quota_weekly' => ['sometimes', 'integer', 'min:0'],
            'quota_monthly' => ['sometimes', 'integer', 'min:0'],
            'used_5h' => ['sometimes', 'integer', 'min:0'],
            'used_weekly' => ['sometimes', 'integer', 'min:0'],
            'used_monthly' => ['sometimes', 'integer', 'min:0'],
            'monthly_usage_threshold' => ['sometimes', 'integer', 'min:0', 'max:100'],
            'priority' => ['sometimes', 'integer', 'min:0'],
            'expires_at' => ['nullable'],
            'status' => ['sometimes', 'integer', 'in:0,1,2'],
            'remark' => ['nullable', 'string', 'max:500'],
        ]);

        $now = time();

        // 到期时间归一化
        if (array_key_exists('expires_at', $data)) {
            $data['expires_at'] = $this->normalizeExpiresAt($data['expires_at']);
        }

        // 配额变更时重置对应窗口的下次重置时间
        if (isset($data['quota_5h']) && $data['quota_5h'] > 0 && $account->reset_5h_at <= 0) {
            $data['reset_5h_at'] = $now + 5 * 3600;
        }
        if (isset($data['quota_weekly']) && $data['quota_weekly'] > 0 && $account->reset_weekly_at <= 0) {
            $data['reset_weekly_at'] = $now + 7 * 24 * 3600;
        }
        if (isset($data['quota_monthly']) && $data['quota_monthly'] > 0 && $account->reset_monthly_at <= 0) {
            $data['reset_monthly_at'] = $now + 30 * 24 * 3600;
        }

        // API Key 加密存储
        if (! empty($data['api_key'])) {
            $data['api_key'] = base64_encode((string) $data['api_key']);
        }

        $data['updated_at'] = $now;

        $account->update($data);

        return $this->success($account->refresh(), '账号已更新');
    }

    /**
     * 删除账号
     * DELETE /coding_plan/accounts/{id}
     */
    public function destroyAccount(int $id): JsonResponse
    {
        $account = CodingPlanAccount::find($id);
        if (! $account) {
            return $this->error('账号不存在', 404);
        }

        $account->delete();

        return $this->success(null, '账号已删除');
    }

    /**
     * 手动重置指定账号的使用计数器
     * POST /coding_plan/accounts/{id}/reset_usage
     */
    public function resetUsage(Request $request, int $id): JsonResponse
    {
        $data = $request->validate([
            'period' => ['nullable', 'string', 'in:5h,weekly,monthly,all'],
        ]);

        $account = CodingPlanAccount::find($id);
        if (! $account) {
            return $this->error('账号不存在', 404);
        }

        $period = $data['period'] ?? 'all';
        $now = time();
        $update = ['updated_at' => $now];

        if ($period === '5h' || $period === 'all') {
            $update['used_5h'] = 0;
            $update['reset_5h_at'] = $now + 5 * 3600;
        }
        if ($period === 'weekly' || $period === 'all') {
            $update['used_weekly'] = 0;
            $update['reset_weekly_at'] = $now + 7 * 24 * 3600;
        }
        if ($period === 'monthly' || $period === 'all') {
            $update['used_monthly'] = 0;
            $update['reset_monthly_at'] = $now + 30 * 24 * 3600;
            // 月度重置后恢复启用状态
            if ($account->status === CodingPlanAccount::STATUS_EXHAUSTED) {
                $update['status'] = CodingPlanAccount::STATUS_ENABLED;
            }
        }

        $account->update($update);

        return $this->success($account->refresh(), '计数器已重置');
    }

    /**
     * 单个账号的使用流水
     * GET /coding_plan/accounts/{id}/usage
     */
    public function accountUsage(Request $request, int $id): JsonResponse
    {
        $account = CodingPlanAccount::find($id);
        if (! $account) {
            return $this->error('账号不存在', 404);
        }

        $query = CodingPlanUsageLog::where('account_id', $id)
            ->when($request->input('success') !== null, fn ($q) => $q->where('success', (bool) $request->input('success')))
            ->when($request->input('start_time'), fn ($q, $v) => $q->where('created_at', '>=', (int) $v))
            ->when($request->input('end_time'), fn ($q, $v) => $q->where('created_at', '<=', (int) $v))
            ->orderByDesc('id');

        $perPage = (int) $request->input('per_page', 20);

        return $this->paginate($query->paginate($perPage));
    }

    /**
     * Coding Plan 类型套餐列表（含关联账号池信息）
     * GET /coding_plan/plans
     */
    public function plans(Request $request): JsonResponse
    {
        $query = SubscriptionPlan::query()
            ->when($request->input('vendor'), fn ($q, $v) => $q->where('coding_vendor', $v))
            ->orderByDesc('id');

        $perPage = (int) $request->input('per_page', 20);

        $paginator = $query->paginate($perPage);

        // 附加每个套餐对应账号池的实时概览
        $paginator->getCollection()->transform(function (SubscriptionPlan $plan) {
            $planArray = $plan->toArray();
            if ($plan->isCodingPlan() && $plan->coding_vendor) {
                $planArray['pool_overview'] = $this->poolService->vendorOverview($plan->coding_vendor);
            }
            return $planArray;
        });

        return $this->paginate($paginator);
    }

    /**
     * 将一个订阅套餐绑定/转换为 Coding Plan 类型
     * POST /coding_plan/plans/{id}/attach
     *
     * 参数: vendor, coding_submits_per_request, coding_quota
     */
    public function attachPlan(Request $request, int $id): JsonResponse
    {
        $plan = SubscriptionPlan::find($id);
        if (! $plan) {
            return $this->error('套餐不存在', 404);
        }

        $data = $request->validate([
            'vendor' => ['required', 'string', 'max:64'],
            'coding_submits_per_request' => ['nullable', 'integer', 'min:1'],
            'coding_quota' => ['nullable', 'integer', 'min:0'],
        ]);

        // 校验该供应商下至少存在一个账号
        $exists = CodingPlanAccount::where('vendor', $data['vendor'])->exists();
        if (! $exists) {
            return $this->error("供应商 [{$data['vendor']}] 下暂无账号，请先创建账号", 422);
        }

        $plan->update([
            'plan_type' => 'coding_plan',
            'coding_vendor' => $data['vendor'],
            'coding_submits_per_request' => $data['coding_submits_per_request'] ?? 1,
            'coding_quota' => $data['coding_quota'] ?? 0,
        ]);

        return $this->success($plan->refresh(), '套餐已绑定到 Coding Plan 账号池');
    }

    /**
     * 将一个套餐从 Coding Plan 类型解绑（还原为 quota 类型）
     * POST /coding_plan/plans/{id}/detach
     */
    public function detachPlan(int $id): JsonResponse
    {
        $plan = SubscriptionPlan::find($id);
        if (! $plan) {
            return $this->error('套餐不存在', 404);
        }

        $plan->update([
            'plan_type' => 'quota',
            'coding_vendor' => null,
            'coding_submits_per_request' => 0,
            'coding_quota' => 0,
        ]);

        return $this->success($plan->refresh(), '套餐已从 Coding Plan 账号池解绑');
    }

    /**
     * 全局统计：各供应商账号池概览 + 最近使用趋势
     * GET /coding_plan/stats
     */
    public function stats(Request $request): JsonResponse
    {
        $vendors = CodingPlanAccount::select('vendor')
            ->distinct()
            ->orderBy('vendor')
            ->pluck('vendor');

        $overview = $vendors->map(function (string $vendor) {
            return $this->poolService->vendorOverview($vendor);
        });

        // 最近 7 天每日提交次数
        $since = time() - 7 * 24 * 3600;
        $daily = CodingPlanUsageLog::selectRaw('FROM_UNIXTIME(created_at, "%Y-%m-%d") as day, SUM(count) as total')
            ->where('created_at', '>=', $since)
            ->groupBy('day')
            ->orderBy('day')
            ->get()
            ->keyBy('day');

        return $this->success([
            'vendors' => $overview,
            'daily_usage_7d' => $daily,
        ]);
    }

    /**
     * 将到期时间归一化为 Unix 秒。
     * 支持: null(=0 永不过期)、int(已为秒)、数字字符串、日期字符串。
     */
    private function normalizeExpiresAt(mixed $value): int
    {
        if ($value === null || $value === '') {
            return 0;
        }
        if (is_int($value)) {
            return $value;
        }
        if (is_string($value)) {
            if (ctype_digit($value)) {
                return (int) $value;
            }
            $ts = strtotime($value);
            return $ts !== false ? $ts : 0;
        }
        return 0;
    }
}
