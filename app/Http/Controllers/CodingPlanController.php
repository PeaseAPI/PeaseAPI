<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Console\Commands\VerifyCodingPlanRatios;
use App\Models\CodingPlanAccount;
use App\Models\CodingPlanModelRatio;
use App\Models\CodingPlanRatioCheck;
use App\Models\CodingPlanUsageLog;
use App\Models\CodingPlanVendor;
use App\Models\CodingPlanVendorTier;
use App\Models\Subscription;
use App\Models\SubscriptionPlan;
use App\Services\CodingPlanCatalog;
use App\Services\CodingPlanPoolService;
use App\Services\CodingPlanRatioService;
use App\Services\OptionService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Schema;
use InvalidArgumentException;

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
        private readonly CodingPlanRatioService $ratioService,
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
            // 计费模式：1=按次提交 2=按积分折算
            'billing_mode' => ['nullable', 'integer', 'in:1,2'],
            // 计数单位显示名（次/点/积分）
            'unit_name' => ['nullable', 'string', 'max:16'],
            // 供应商单位 → 平台积分汇率
            'unit_exchange_rate' => ['nullable', 'numeric', 'min:0', 'max:999999'],
            'account_name' => ['required', 'string', 'max:128'],
            'channel_id' => ['nullable', 'integer', 'min:0'],
            'api_key' => ['nullable', 'string'],
            'base_url' => ['nullable', 'string', 'max:255'],
            // 积分制下配额支持小数
            'quota_5h' => ['nullable', 'numeric', 'min:0'],
            'quota_weekly' => ['nullable', 'numeric', 'min:0'],
            'quota_monthly' => ['nullable', 'numeric', 'min:0'],
            'used_5h' => ['nullable', 'numeric', 'min:0'],
            'used_weekly' => ['nullable', 'numeric', 'min:0'],
            'used_monthly' => ['nullable', 'numeric', 'min:0'],
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
        $data['billing_mode'] ??= CodingPlanAccount::BILLING_MODE_PER_REQUEST;
        $data['unit_name'] ??= '';
        $data['unit_exchange_rate'] ??= 0; // 0 = 跟随供应商默认汇率

        // 积分模式且未填单位名时给出默认
        if ($data['unit_name'] === '' && (int) $data['billing_mode'] === CodingPlanAccount::BILLING_MODE_CREDIT) {
            $data['unit_name'] = '积分';
        }

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
            'billing_mode' => ['sometimes', 'integer', 'in:1,2'],
            'unit_name' => ['nullable', 'string', 'max:16'],
            'unit_exchange_rate' => ['nullable', 'numeric', 'min:0', 'max:999999'],
            'account_name' => ['sometimes', 'string', 'max:128'],
            'channel_id' => ['nullable', 'integer', 'min:0'],
            'api_key' => ['nullable', 'string'],
            'base_url' => ['nullable', 'string', 'max:255'],
            'quota_5h' => ['sometimes', 'numeric', 'min:0'],
            'quota_weekly' => ['sometimes', 'numeric', 'min:0'],
            'quota_monthly' => ['sometimes', 'numeric', 'min:0'],
            'used_5h' => ['sometimes', 'numeric', 'min:0'],
            'used_weekly' => ['sometimes', 'numeric', 'min:0'],
            'used_monthly' => ['sometimes', 'numeric', 'min:0'],
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
            ->when($request->input('success') !== null, fn ($q) => $q->where('success', filter_var(
                $request->input('success'),
                FILTER_VALIDATE_BOOLEAN
            )))
            ->when($request->input('start_time'), fn ($q, $v) => $q->where('created_at', '>=', (int) $v))
            ->when($request->input('end_time'), fn ($q, $v) => $q->where('created_at', '<=', (int) $v))
            ->orderByDesc('id');

        $perPage = (int) $request->input('per_page', 20);

        return $this->paginate($query->paginate($perPage));
    }

    /**
     * 供应商列表（含各自默认汇率）
     * GET /coding_plan/vendors
     */
    public function vendors(Request $request): JsonResponse
    {
        $vendors = CodingPlanVendor::query()
            ->when($request->input('status') !== null && $request->input('status') !== '',
                fn ($q, $v) => $q->where('status', (int) $v))
            ->orderBy('sort')->orderBy('id')->get();

        // 附带账号池规模，便于管理页展示
        $counts = CodingPlanAccount::selectRaw('vendor, count(*) as total, sum(case when status = 1 then 1 else 0 end) as active')
            ->groupBy('vendor')
            ->get()
            ->keyBy('vendor');

        $data = $vendors->map(function (CodingPlanVendor $v) use ($counts) {
            $arr = $v->toArray();
            $arr['accounts_total'] = (int) ($counts[$v->code]->total ?? 0);
            $arr['accounts_active'] = (int) ($counts[$v->code]->active ?? 0);

            return $arr;
        });

        return $this->success($data);
    }

    /**
     * 新增供应商
     * POST /coding_plan/vendors
     */
    public function storeVendor(Request $request): JsonResponse
    {
        $data = $request->validate([
            // 供应商标识（与 coding_plan_accounts.vendor / 比率表 vendor 对应）
            'code' => ['required', 'string', 'max:64', 'unique:coding_plan_vendors,code'],
            'name' => ['required', 'string', 'max:128'],
            'logo' => ['nullable', 'string', 'max:255'],
            // 默认计费模式：1=按次 2=按积分
            'billing_mode' => ['nullable', 'integer', 'in:1,2'],
            // 产品类型：1=订阅制 Coding Plan 2=按量 Token Plan
            'plan_kind' => ['nullable', 'integer', 'in:1,2'],
            'unit_name' => ['nullable', 'string', 'max:16'],
            'unit_exchange_rate' => ['nullable', 'numeric', 'min:0'],
            'docs_url' => ['nullable', 'string', 'max:255'],
            'pricing_source_url' => ['nullable', 'string', 'max:255'],
            'status' => ['nullable', 'integer', 'in:0,1'],
            'sort' => ['nullable', 'integer', 'min:0'],
            'remark' => ['nullable', 'string', 'max:500'],
        ]);

        $data['billing_mode'] ??= CodingPlanVendor::BILLING_MODE_PER_REQUEST;
        $data['plan_kind'] ??= CodingPlanVendor::PLAN_KIND_CODING;
        $data['unit_name'] ??= '';
        $data['unit_exchange_rate'] ??= 1.0;
        $data['status'] ??= 1;
        $data['sort'] ??= 0;
        $data['created_at'] = $data['updated_at'] = time();

        $vendor = CodingPlanVendor::create($data);
        $this->ratioService->flushCache($data['code']);

        return $this->success($vendor, '供应商已创建');
    }

    /**
     * 更新供应商
     * PUT /coding_plan/vendors/{id}
     */
    public function updateVendor(Request $request, int $id): JsonResponse
    {
        $vendor = CodingPlanVendor::find($id);
        if (! $vendor) {
            return $this->error('供应商不存在', 404);
        }

        $data = $request->validate([
            'code' => ['sometimes', 'string', 'max:64', 'unique:coding_plan_vendors,code,'.$id],
            'name' => ['sometimes', 'string', 'max:128'],
            'logo' => ['nullable', 'string', 'max:255'],
            'billing_mode' => ['sometimes', 'integer', 'in:1,2'],
            'plan_kind' => ['sometimes', 'integer', 'in:1,2'],
            'unit_name' => ['nullable', 'string', 'max:16'],
            'unit_exchange_rate' => ['nullable', 'numeric', 'min:0'],
            'docs_url' => ['nullable', 'string', 'max:255'],
            'pricing_source_url' => ['nullable', 'string', 'max:255'],
            'status' => ['sometimes', 'integer', 'in:0,1'],
            'sort' => ['sometimes', 'integer', 'min:0'],
            'remark' => ['nullable', 'string', 'max:500'],
        ]);

        $data['updated_at'] = time();
        $vendor->update($data);
        // 全量清理：覆盖 status/exchange_rate 变更及可能的 code 改名（新旧键）
        $this->ratioService->flushCache();

        return $this->success($vendor->refresh(), '供应商已更新');
    }

    /**
     * 删除供应商（同时删除其比率配置）
     * DELETE /coding_plan/vendors/{id}
     */
    public function destroyVendor(int $id): JsonResponse
    {
        $vendor = CodingPlanVendor::find($id);
        if (! $vendor) {
            return $this->error('供应商不存在', 404);
        }

        CodingPlanModelRatio::where('vendor', $vendor->code)->delete();
        CodingPlanVendorTier::where('vendor_code', $vendor->code)->delete();
        // 先按 code 精准清缓存（厂商行删除后全量清理已无法枚举到该 code）
        $this->ratioService->flushCache($vendor->code);
        $vendor->delete();

        return $this->success(null, '供应商已删除');
    }

    /**
     * 模型折算比率列表
     * GET /coding_plan/ratios?vendor=xxx
     */
    public function ratios(Request $request): JsonResponse
    {
        $query = CodingPlanModelRatio::query()
            ->when($request->input('vendor'), fn ($q, $v) => $q->where('vendor', $v))
            ->when($request->input('status') !== null && $request->input('status') !== '',
                fn ($q, $v) => $q->where('status', (int) $v))
            ->orderBy('vendor')->orderBy('match_type')->orderBy('model');

        $perPage = (int) $request->input('per_page', 50);

        $paginator = $query->paginate($perPage);

        // 附加 stale 标记（超过核对窗口未人工复核，供管理端 RatiosTab 提示）
        $staleBefore = time() - max(1, (int) OptionService::get('CodingPlanRatioStaleDays', 7)) * 86400;
        $paginator->getCollection()->transform(function (CodingPlanModelRatio $ratio) use ($staleBefore) {
            $ratio->stale = $ratio->updated_at > 0 && $ratio->updated_at < $staleBefore;

            return $ratio;
        });

        return $this->paginate($paginator);
    }

    /**
     * 新增模型折算比率
     * POST /coding_plan/ratios
     */
    public function storeRatio(Request $request): JsonResponse
    {
        $data = $request->validate([
            'vendor' => ['required', 'string', 'max:64'],
            // 模型名：match_type=exact 全等 / prefix 前缀（前缀最长优先）
            'model' => ['required', 'string', 'max:128'],
            'match_type' => ['nullable', 'string', 'in:exact,prefix'],
            'cost_mode' => ['nullable', 'string', 'in:per_request,per_1k_tokens,per_token_parts'],
            // 单位成本：按次/按千 token 口径消耗的供应商单位数（分段口径不参与计算）
            'unit_cost' => ['required', 'numeric', 'min:0', 'max:999999'],
            // 分段折算（per_token_parts）：输入/缓存命中/输出的千 token 折算系数
            'input_rate' => ['nullable', 'numeric', 'min:0', 'max:999999'],
            'cached_rate' => ['nullable', 'numeric', 'min:0', 'max:999999'],
            'output_rate' => ['nullable', 'numeric', 'min:0', 'max:999999'],
            'status' => ['nullable', 'integer', 'in:0,1'],
            'sort' => ['nullable', 'integer', 'min:0'],
            'remark' => ['nullable', 'string', 'max:255'],
        ]);

        $data['match_type'] = $data['match_type'] ?? CodingPlanModelRatio::MATCH_EXACT;
        $data['cost_mode'] = $data['cost_mode'] ?? CodingPlanModelRatio::COST_PER_REQUEST;
        $data['input_rate'] ??= 0;
        $data['cached_rate'] ??= 0;
        $data['output_rate'] ??= 0;
        $data['status'] ??= 1;
        $data['sort'] ??= 0;
        $data['created_at'] = $data['updated_at'] = time();

        // 同厂商 + 同匹配模式 + 同模型保持唯一
        $exists = CodingPlanModelRatio::where('vendor', $data['vendor'])
            ->where('match_type', $data['match_type'])
            ->where('model', $data['model'])
            ->exists();
        if ($exists) {
            return $this->error('该厂商下已存在相同匹配模式与模型的比率配置', 422);
        }

        $ratio = CodingPlanModelRatio::create($data);
        $this->ratioService->flushCache($data['vendor']);

        return $this->success($ratio, '比率已创建');
    }

    /**
     * 更新模型折算比率
     * PUT /coding_plan/ratios/{id}
     */
    public function updateRatio(Request $request, int $id): JsonResponse
    {
        $ratio = CodingPlanModelRatio::find($id);
        if (! $ratio) {
            return $this->error('比率配置不存在', 404);
        }

        $data = $request->validate([
            'vendor' => ['sometimes', 'string', 'max:64'],
            'model' => ['sometimes', 'string', 'max:128'],
            'match_type' => ['sometimes', 'string', 'in:exact,prefix'],
            'cost_mode' => ['sometimes', 'string', 'in:per_request,per_1k_tokens,per_token_parts'],
            'unit_cost' => ['sometimes', 'numeric', 'min:0', 'max:999999'],
            'input_rate' => ['nullable', 'numeric', 'min:0', 'max:999999'],
            'cached_rate' => ['nullable', 'numeric', 'min:0', 'max:999999'],
            'output_rate' => ['nullable', 'numeric', 'min:0', 'max:999999'],
            'status' => ['sometimes', 'integer', 'in:0,1'],
            'sort' => ['sometimes', 'integer', 'min:0'],
            'remark' => ['nullable', 'string', 'max:255'],
        ]);

        $data['updated_at'] = time();
        $ratio->update($data);
        $this->ratioService->flushCache($ratio->refresh()->vendor);

        return $this->success($ratio->refresh(), '比率已更新');
    }

    /**
     * 删除模型折算比率
     * DELETE /coding_plan/ratios/{id}
     */
    public function destroyRatio(int $id): JsonResponse
    {
        $ratio = CodingPlanModelRatio::find($id);
        if (! $ratio) {
            return $this->error('比率配置不存在', 404);
        }

        $vendor = $ratio->vendor;
        $ratio->delete();
        $this->ratioService->flushCache($vendor);

        return $this->success(null, '比率已删除');
    }

    /**
     * 官方模板目录（App\Services\CodingPlanCatalog 的只读视图）
     * GET /coding_plan/catalog
     *
     * 返回各厂商官方套餐档位/折算标准模板与库内落地状态，
     * 供管理端「官方同步」页展示「从模板添加 / 同步模板」入口。
     */
    public function catalog(): JsonResponse
    {
        $vendorExists = CodingPlanVendor::query()->select('code')->pluck('code')->flip();
        $tierCounts = CodingPlanVendorTier::query()
            ->selectRaw('vendor_code, count(*) as aggregate')
            ->groupBy('vendor_code')
            ->pluck('aggregate', 'vendor_code');
        $ratioCounts = CodingPlanModelRatio::query()
            ->selectRaw('vendor, count(*) as aggregate')
            ->groupBy('vendor')
            ->pluck('aggregate', 'vendor');

        $templates = collect(CodingPlanCatalog::templates())
            ->map(fn (array $template, string $code) => [
                'code' => $code,
                'name' => $template['name'],
                'plan_kind' => $template['plan_kind'],
                'billing_mode' => $template['billing_mode'],
                'unit_name' => $template['unit_name'],
                'docs_url' => $template['docs_url'],
                'verified_at' => $template['verified_at'],
                'notes' => $template['notes'],
                'tier_count' => count($template['tiers']),
                'ratio_count' => count($template['ratios']),
                'vendor_exists' => $vendorExists->has($code),
                'db_tier_count' => (int) ($tierCounts[$code] ?? 0),
                'db_ratio_count' => (int) ($ratioCounts[$code] ?? 0),
            ])
            ->values();

        return $this->success($templates);
    }

    /**
     * 幂等落地一个官方模板（新建厂商 + 预置档位/折算标准，全部默认停用态）
     * POST /coding_plan/catalog/{code}/apply
     *
     * body: { activate_vendor?: bool } —— true 时把厂商置为启用（仍要求管理员
     * 自行设置 unit_exchange_rate 并启用折算比率；预置比率 status=0 不会自动计费）。
     */
    public function applyCatalog(Request $request, string $code): JsonResponse
    {
        $data = $request->validate([
            'activate_vendor' => ['nullable', 'boolean'],
        ]);

        try {
            $result = CodingPlanCatalog::apply($code);
        } catch (InvalidArgumentException) {
            return $this->error("官方模板不存在: {$code}", 404);
        }

        $this->ratioService->flushCache($code);

        $message = "模板已应用：档位 新增 {$result['tiers']['inserted']}/更新 {$result['tiers']['updated']}/跳过 {$result['tiers']['skipped']}，"
            ."折算标准 新增 {$result['ratios']['inserted']}/更新 {$result['ratios']['updated']}/跳过 {$result['ratios']['skipped']}";

        $vendor = null;
        if ($data['activate_vendor'] ?? false) {
            $vendor = CodingPlanVendor::query()->where('code', $code)->first();
            if ($vendor !== null && (int) $vendor->status !== 1) {
                $vendor->update(['status' => 1, 'updated_at' => time()]);
                $this->ratioService->flushCache($code);
                $message .= '；厂商已启用（请先设置 unit_exchange_rate 并启用对应折算比率，否则不会有实际计费）';
            }
        }

        return $this->success(['result' => $result, 'vendor' => $vendor?->refresh()?->toArray()], $message);
    }

    /**
     * 各供应商最近一次定时校对结果（含待确认变更清单）
     * GET /coding_plan/checks
     *
     * changes 内每条变更带 key（应用/忽略用）与 ignored 标记；
     * change_count 仅统计未忽略条目（new + changed + missing）。
     */
    public function checks(): JsonResponse
    {
        if (! Schema::hasTable('coding_plan_ratio_checks')) {
            return $this->success(['pending_total' => 0, 'items' => []]);
        }

        $latestIds = CodingPlanRatioCheck::query()
            ->selectRaw('max(id) as id')
            ->groupBy('vendor')
            ->pluck('id');

        $items = CodingPlanRatioCheck::query()
            ->whereIn('id', $latestIds)
            ->orderBy('vendor')
            ->get()
            ->map(function (CodingPlanRatioCheck $check) {
                $arr = $check->toArray();
                $arr['changes'] = json_decode((string) $check->changes, true) ?: (object) [];
                $arr['pending_keys'] = json_decode((string) $check->pending_keys, true) ?: [];

                return $arr;
            })
            ->values();

        $pendingTotal = (int) $items->sum('change_count');

        return $this->success(['pending_total' => $pendingTotal, 'items' => $items]);
    }

    /**
     * 忽略 / 恢复一条待确认变更（管理员不认可定价源的变更时使用）
     * POST /coding_plan/checks/ignore
     *
     * body: { vendor: string, key: "kind|model|match_type", undo?: bool }
     * 忽略键存 CodingPlanRatioIgnoreKeys（verify-ratios 写流水时标 ignored=true 且不计入 change_count）。
     */
    public function ignoreCheckChange(Request $request): JsonResponse
    {
        $data = $request->validate([
            'vendor' => ['required', 'string', 'max:64'],
            // "kind|model|match_type"，kind ∈ new/changed/missing
            'key' => ['required', 'string', 'max:255'],
            'undo' => ['nullable', 'boolean'],
        ]);

        $fullKey = $data['vendor'].'|'.$data['key'];
        // Option::get 对 JSON 值自动 decode → 可能返回 array 或 JSON 字符串，双态兼容
        $ignoreRaw = OptionService::get(VerifyCodingPlanRatios::IGNORE_KEYS_OPTION, '[]');
        $keys = is_array($ignoreRaw) ? $ignoreRaw : (json_decode((string) $ignoreRaw, true) ?: []);
        if (! is_array($keys)) {
            $keys = [];
        }

        $exists = in_array($fullKey, $keys, true);
        $undo = (bool) ($data['undo'] ?? false);
        if ($undo && $exists) {
            $keys = array_values(array_diff($keys, [$fullKey]));
        } elseif (! $undo && ! $exists) {
            $keys[] = $fullKey;
        }

        OptionService::set(VerifyCodingPlanRatios::IGNORE_KEYS_OPTION, json_encode(array_values($keys), JSON_UNESCAPED_UNICODE));

        return $this->success(['keys' => $keys, 'ignored' => ! $undo], $undo ? '已恢复该变更提醒' : '已忽略该变更提醒');
    }

    /**
     * 供应商官方套餐档位列表
     * GET /coding_plan/tiers?vendor_code=xxx&status=1
     */
    public function tiers(Request $request): JsonResponse
    {
        $tiers = CodingPlanVendorTier::query()
            ->when($request->input('vendor_code'), fn ($q, $v) => $q->where('vendor_code', $v))
            ->when($request->input('status') !== null && $request->input('status') !== '',
                fn ($q, $v) => $q->where('status', (int) $v))
            ->orderBy('vendor_code')
            ->orderBy('sort')
            ->orderBy('id')
            ->get();

        return $this->success($tiers);
    }

    /**
     * 新增供应商套餐档位
     * POST /coding_plan/tiers
     */
    public function storeTier(Request $request): JsonResponse
    {
        $data = $request->validate([
            'vendor_code' => ['required', 'string', 'max:64'],
            'name' => ['required', 'string', 'max:64'],
            // 官方价格（可空 = 待核对，仅展示 price_note）
            'price' => ['nullable', 'numeric', 'min:0', 'max:99999999'],
            'price_note' => ['nullable', 'string', 'max:64'],
            'period' => ['nullable', 'string', 'max:16'],
            'quota' => ['nullable', 'numeric', 'min:0', 'max:999999999999'],
            'quota_unit' => ['nullable', 'string', 'max:32'],
            'quota_note' => ['nullable', 'string', 'max:128'],
            'status' => ['nullable', 'integer', 'in:0,1'],
            'sort' => ['nullable', 'integer', 'min:0'],
            'remark' => ['nullable', 'string', 'max:255'],
        ]);

        $data['status'] ??= 1;
        $data['sort'] ??= 0;
        $data['created_at'] = $data['updated_at'] = time();

        $tier = CodingPlanVendorTier::create($data);
        $this->ratioService->flushCache($data['vendor_code']);

        return $this->success($tier, '套餐档位已创建');
    }

    /**
     * 更新供应商套餐档位
     * PUT /coding_plan/tiers/{id}
     */
    public function updateTier(Request $request, int $id): JsonResponse
    {
        $tier = CodingPlanVendorTier::find($id);
        if (! $tier) {
            return $this->error('套餐档位不存在', 404);
        }

        $data = $request->validate([
            'vendor_code' => ['sometimes', 'string', 'max:64'],
            'name' => ['sometimes', 'string', 'max:64'],
            'price' => ['nullable', 'numeric', 'min:0', 'max:99999999'],
            'price_note' => ['nullable', 'string', 'max:64'],
            'period' => ['nullable', 'string', 'max:16'],
            'quota' => ['nullable', 'numeric', 'min:0', 'max:999999999999'],
            'quota_unit' => ['nullable', 'string', 'max:32'],
            'quota_note' => ['nullable', 'string', 'max:128'],
            'status' => ['sometimes', 'integer', 'in:0,1'],
            'sort' => ['sometimes', 'integer', 'min:0'],
            'remark' => ['nullable', 'string', 'max:255'],
        ]);

        $data['updated_at'] = time();
        $tier->update($data);
        $this->ratioService->flushCache($tier->refresh()->vendor_code);

        return $this->success($tier->refresh(), '套餐档位已更新');
    }

    /**
     * 删除供应商套餐档位
     * DELETE /coding_plan/tiers/{id}
     */
    public function destroyTier(int $id): JsonResponse
    {
        $tier = CodingPlanVendorTier::find($id);
        if (! $tier) {
            return $this->error('套餐档位不存在', 404);
        }

        $vendorCode = $tier->vendor_code;
        $tier->delete();
        $this->ratioService->flushCache($vendorCode);

        return $this->success(null, '套餐档位已删除');
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
    public function detachPlan(Request $request, int $id): JsonResponse
    {
        $plan = SubscriptionPlan::find($id);
        if (! $plan) {
            return $this->error('套餐不存在', 404);
        }

        // 守卫：解绑后 plan_type 还原为 quota，中转侧按
        // plan_type='coding_plan' join 的订阅解析将失效，
        // 已付费用户的 Coding Plan 服务会静默中断，故默认拦截。
        // 确需强制解绑时可显式传 force=1（订阅记录保留，重新绑定后可续用）。
        $activeCount = Subscription::query()
            ->where('plan_id', $plan->id)
            ->where('status', 1)
            ->where(function ($q) {
                $now = time();
                $q->where('period_end', 0)->orWhere('period_end', '>', $now);
            })
            ->count();
        if ($activeCount > 0 && ! $request->boolean('force')) {
            return $this->error(
                "该套餐仍有 {$activeCount} 个生效中的订阅，解绑会导致这些用户无法继续使用；请先处理相关订阅，或确认后传 force=1 强制解绑",
                422
            );
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
     * 全局统计：各供应商账号池概览（原生单位 + 平台积分双口径）+ 最近使用趋势
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

        // 最近 7 天每日消耗趋势
        // count 口径 = 按次模式的提交数；credits 口径 = 积分模式的平台积分（统一折算池）
        $since = time() - 7 * 24 * 3600;
        $daily = CodingPlanUsageLog::selectRaw(
            'FROM_UNIXTIME(created_at, "%Y-%m-%d") as day, '.
            'SUM(CASE WHEN success = 1 THEN count ELSE 0 END) as submits, '.
            'SUM(CASE WHEN success = 1 THEN units ELSE 0 END) as units, '.
            'SUM(CASE WHEN success = 1 THEN credits ELSE 0 END) as credits'
        )
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
     * 公开抵扣产品介绍数据（无需登录，供介绍页 /coding-plan 与外部集成消费）
     * GET /api/coding_plan/offers
     *
     * 返回启用供应商及其启用的模型折算比率、两级折算口径与最近校对状态
     * （stale_count > 0 表示该供应商存在超过核对窗口未人工复核的比率）。
     * 聚合实现统一在 CodingPlanRatioService::publicOffers()，缓存 300s，
     * 任何比率/供应商写入经 flushCache() 即时失效。
     */
    public function publicOffers(): JsonResponse
    {
        $offers = Cache::remember('coding_plan_offers', 300, fn () => $this->ratioService->publicOffers());

        return $this->success($offers);
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
