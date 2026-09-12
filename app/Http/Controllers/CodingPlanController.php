<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Console\Commands\VerifyCodingPlanRatios;
use App\Models\CodingPlanAccount;
use App\Models\CodingPlanModelRatio;
use App\Models\CodingPlanPromotion;
use App\Models\CodingPlanRatioCheck;
use App\Models\CodingPlanUsageLog;
use App\Models\CodingPlanVendor;
use App\Models\CodingPlanVendorTier;
use App\Models\CurrencyRate;
use App\Models\Subscription;
use App\Models\SubscriptionPlan;
use App\Services\CodingPlanCatalog;
use App\Services\CodingPlanOfficialSourceService;
use App\Services\CodingPlanPoolService;
use App\Services\CodingPlanRatioService;
use App\Services\CurrencyExchangeService;
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
        private readonly CodingPlanOfficialSourceService $officialSources,
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
            // 官方计价币种（ISO 4217；P7-1 迁移 000010，缺省 CNY）
            'currency' => ['nullable', 'string', 'max:8'],
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
        $data['currency'] ??= CurrencyExchangeService::BASE_CURRENCY;
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
            // 官方计价币种（ISO 4217；P7-1 迁移 000010，缺省 CNY）
            'currency' => ['nullable', 'string', 'max:8'],
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
            // 分时段折扣窗口（智谱非高峰/DeepSeek 空闲等官方口径）：数组或 JSON 字符串，服务端规范化
            'time_discounts' => ['nullable'],
            'status' => ['nullable', 'integer', 'in:0,1'],
            'sort' => ['nullable', 'integer', 'min:0'],
            'remark' => ['nullable', 'string', 'max:255'],
        ]);

        $data['match_type'] = $data['match_type'] ?? CodingPlanModelRatio::MATCH_EXACT;
        $data['cost_mode'] = $data['cost_mode'] ?? CodingPlanModelRatio::COST_PER_REQUEST;
        $data['input_rate'] ??= 0;
        $data['cached_rate'] ??= 0;
        $data['output_rate'] ??= 0;
        $data['time_discounts'] = array_key_exists('time_discounts', $data)
            ? CodingPlanModelRatio::normalizeTimeDiscounts($data['time_discounts'])
            : null;
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
            // 分时段折扣窗口：数组或 JSON 字符串，服务端规范化（null 清空=全时段原价）
            'time_discounts' => ['nullable'],
            'status' => ['sometimes', 'integer', 'in:0,1'],
            'sort' => ['sometimes', 'integer', 'min:0'],
            'remark' => ['nullable', 'string', 'max:255'],
        ]);

        if (array_key_exists('time_discounts', $data)) {
            $data['time_discounts'] = CodingPlanModelRatio::normalizeTimeDiscounts($data['time_discounts']);
        }

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
     * 厂商模型上架清单（P8-1：库内比率行 × 官方目录快照 × 校对目录变更）
     * GET /coding_plan/vendors/{code}/models
     *
     * 每行 official 状态（与最新官方目录快照实时比对，diffCatalogModels 同口径：
     * 仅 match_type=exact 行参与存在性比对、模型名小写归一）：
     *  - in_catalog：官方目录在列（已提供/已停用看 status）
     *  - missing：官方目录未列（下架嫌疑，可结合 promotions/model_retirement 处置）
     *  - new：官方目录新增、库内无对应行（虚拟行 id=null，P8-3 应用后落地为停用态行）
     *  - unknown：无目录快照，或 prefix 行（目录不含匹配模式，不做前缀推断）
     * catalog_change 取最近一次校对流水（coding_plan_ratio_checks）的 model_catalog
     * 条目（P8-3 高亮/应用/忽略依据；ignored=true 表示管理员已忽略）。
     */
    public function vendorModels(string $code): JsonResponse
    {
        $vendor = CodingPlanVendor::query()->where('code', $code)->first();
        if (! $vendor) {
            return $this->error('供应商不存在', 404);
        }

        $ratios = CodingPlanModelRatio::query()
            ->where('vendor', $code)
            ->orderBy('match_type')
            ->orderBy('model')
            ->orderBy('id')
            ->get();

        [$catalogModels, $catalogFetchedAt] = $this->officialSources->latestCatalog($code);
        $hasCatalog = $catalogModels !== [];

        // 最近一次校对流水的目录变更（model 小写归一 → 条目，含 ignored 标记与 key）
        $catalogChanges = [];
        $latestCheck = CodingPlanRatioCheck::query()
            ->where('vendor', $code)
            ->orderByDesc('id')
            ->first();
        if ($latestCheck !== null) {
            $changes = json_decode((string) $latestCheck->changes, true) ?: [];
            foreach ((array) ($changes['model_catalog'] ?? []) as $item) {
                if (is_array($item) && isset($item['model'])) {
                    $catalogChanges[mb_strtolower((string) $item['model'])] = $item;
                }
            }
        }

        // stale 口径与 ratios() 一致（超过核对窗口未人工复核）
        $staleBefore = time() - max(1, (int) OptionService::get('CodingPlanRatioStaleDays', 7)) * 86400;

        $rows = [];
        $coveredExact = [];
        foreach ($ratios as $ratio) {
            $official = 'unknown';
            if ($hasCatalog && $ratio->match_type === CodingPlanModelRatio::MATCH_EXACT) {
                $key = mb_strtolower($ratio->model);
                $official = isset($catalogModels[$key]) ? 'in_catalog' : 'missing';
                $coveredExact[$key] = true;
            }
            $row = $ratio->toArray();
            $row['stale'] = $ratio->updated_at > 0 && $ratio->updated_at < $staleBefore;
            $row['official'] = $official;
            $change = $catalogChanges[mb_strtolower($ratio->model)] ?? null;
            $row['catalog_change'] = $change;
            $row['change_ignored'] = (bool) ($change['ignored'] ?? false);
            $rows[] = $row;
        }

        // 官方新增：目录在列但库内无对应 exact 行 → 虚拟行（id=null，待应用/忽略）
        if ($hasCatalog) {
            foreach ($catalogModels as $key => $original) {
                if (isset($coveredExact[$key])) {
                    continue;
                }
                $change = $catalogChanges[$key] ?? null;
                $rows[] = [
                    'id' => null,
                    'vendor' => $code,
                    'model' => $original,
                    'match_type' => CodingPlanModelRatio::MATCH_EXACT,
                    'cost_mode' => null,
                    'unit_cost' => null,
                    'input_rate' => null,
                    'cached_rate' => null,
                    'output_rate' => null,
                    'time_discounts' => null,
                    'status' => null,
                    'sort' => null,
                    'remark' => null,
                    'stale' => false,
                    'official' => 'new',
                    'catalog_change' => $change,
                    'change_ignored' => (bool) ($change['ignored'] ?? false),
                ];
            }
        }

        $summary = [
            'total' => count($rows),
            'enabled' => count(array_filter($rows, fn (array $r): bool => ($r['status'] ?? null) === 1)),
            'disabled' => count(array_filter($rows, fn (array $r): bool => ($r['status'] ?? null) === 0)),
            'official_new' => count(array_filter($rows, fn (array $r): bool => $r['official'] === 'new')),
            'official_missing' => count(array_filter($rows, fn (array $r): bool => $r['official'] === 'missing')),
            'catalog_total' => $hasCatalog ? count($catalogModels) : null,
            'catalog_fetched_at' => $catalogFetchedAt,
        ];

        return $this->success([
            'vendor' => $vendor->toArray(),
            'models' => $rows,
            'summary' => $summary,
        ]);
    }

    /**
     * 批量启用/停用模型比率行（P8-2 上架流：清单勾选 → 批量上下架）
     * POST /coding_plan/vendors/{code}/models/batch_status
     *
     * body: { ids: number[], status: 0|1 }
     * 联动：失效比率聚合缓存（offers/ratios）+ channel_cache（与 SyncChannelCache
     * 同口径 forget，渠道列表缓存重建后模型可见性即时生效）。
     */
    public function batchUpdateModelStatus(Request $request, string $code): JsonResponse
    {
        if (! CodingPlanVendor::query()->where('code', $code)->exists()) {
            return $this->error('供应商不存在', 404);
        }

        $data = $request->validate([
            'ids' => ['required', 'array', 'min:1', 'max:500'],
            'ids.*' => ['integer'],
            'status' => ['required', 'integer', 'in:0,1'],
        ]);

        $updated = CodingPlanModelRatio::query()
            ->where('vendor', $code)
            ->whereIn('id', $data['ids'])
            ->update(['status' => (int) $data['status'], 'updated_at' => time()]);

        $this->ratioService->flushCache($code);
        Cache::forget('channel_cache');

        $label = (int) $data['status'] === 1 ? '启用' : '停用';

        return $this->success(['updated' => $updated], "已{$label} {$updated} 个模型");
    }

    /**
     * 应用官方目录变更（P8-3：model_catalog new/missing 一键应用）
     * POST /coding_plan/catalog_changes/apply
     *
     * body: { vendor: string, action: "new"|"missing", models: string[] }
     *  - new：为官方新增模型落地停用态 exact 行（per_request unit_cost=1，remark
     *    标注待配置定价；status=0 不会自动计费），模型名优先取快照原样大小写；
     *  - missing：停用对应 exact 行（可逆，管理员可再启用），并把 model_catalog
     *    忽略键写入 IGNORE_KEYS_OPTION（停用行仍会出现在后续同步 diff 中，防止重复提醒）。
     * 铁律同 verify-ratios：不自动改价、不自动启用 —— 上架动作始终由管理员显式执行。
     */
    public function applyCatalogChanges(Request $request): JsonResponse
    {
        $data = $request->validate([
            'vendor' => ['required', 'string', 'max:64'],
            'action' => ['required', 'string', 'in:new,missing'],
            'models' => ['required', 'array', 'min:1', 'max:500'],
            'models.*' => ['string', 'max:128'],
        ]);

        $code = $data['vendor'];
        if (! CodingPlanVendor::query()->where('code', $code)->exists()) {
            return $this->error('供应商不存在', 404);
        }

        $models = array_values(array_unique($data['models']));
        $now = time();
        $applied = 0;
        $skipped = 0;

        if ($data['action'] === 'new') {
            // 快照原样名保真：校对流水的目录条目为小写归一名，落库须用官方原样
            [$catalogModels] = $this->officialSources->latestCatalog($code);
            foreach ($models as $model) {
                $original = $catalogModels[mb_strtolower($model)] ?? $model;
                $exists = CodingPlanModelRatio::query()
                    ->where('vendor', $code)
                    ->where('match_type', CodingPlanModelRatio::MATCH_EXACT)
                    ->whereRaw('lower(model) = ?', [mb_strtolower($original)])
                    ->exists();
                if ($exists) {
                    $skipped++;

                    continue;
                }
                CodingPlanModelRatio::create([
                    'vendor' => $code,
                    'model' => $original,
                    'match_type' => CodingPlanModelRatio::MATCH_EXACT,
                    'cost_mode' => CodingPlanModelRatio::COST_PER_REQUEST,
                    'unit_cost' => 1,
                    'input_rate' => 0,
                    'cached_rate' => 0,
                    'output_rate' => 0,
                    'time_discounts' => null,
                    // 默认停用：定价待管理员配置，不会自动计费
                    'status' => 0,
                    'sort' => 0,
                    'remark' => '官方目录新增（P8-3 一键应用，待配置定价）',
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
                $applied++;
            }
        } else {
            $targets = CodingPlanModelRatio::query()
                ->where('vendor', $code)
                ->where('match_type', CodingPlanModelRatio::MATCH_EXACT)
                ->get();
            $wanted = array_flip(array_map('mb_strtolower', $models));

            // 忽略键双态兼容（与 ignoreCheckChange 同构）；目录条目 model 为小写归一名
            $ignoreRaw = OptionService::get(VerifyCodingPlanRatios::IGNORE_KEYS_OPTION, '[]');
            $keys = is_array($ignoreRaw) ? $ignoreRaw : (json_decode((string) $ignoreRaw, true) ?: []);
            if (! is_array($keys)) {
                $keys = [];
            }
            $ignoredSet = array_flip($keys);

            foreach ($targets as $ratio) {
                if (! isset($wanted[mb_strtolower($ratio->model)])) {
                    continue;
                }
                if ((int) $ratio->status !== 0) {
                    $ratio->update(['status' => 0, 'updated_at' => $now]);
                    $applied++;
                } else {
                    $skipped++;
                }
                $fullKey = $code.'|model_catalog|'.mb_strtolower($ratio->model).'|';
                if (! isset($ignoredSet[$fullKey])) {
                    $keys[] = $fullKey;
                    $ignoredSet[$fullKey] = true;
                }
            }

            OptionService::set(VerifyCodingPlanRatios::IGNORE_KEYS_OPTION, json_encode(array_values($keys), JSON_UNESCAPED_UNICODE));
        }

        $this->ratioService->flushCache($code);
        Cache::forget('channel_cache');

        $label = $data['action'] === 'new' ? '落地新增模型' : '停用下架模型';

        return $this->success(
            ['applied' => $applied, 'skipped' => $skipped],
            "已{$label} {$applied} 个（跳过 {$skipped} 个）"
        );
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
     * 内置官方定价源（App\Services\CodingPlanCatalog 折算标准的 JSON 视图）
     * GET /coding_plan/pricing_source/{code}（公开：校对命令服务端拉取，无法携带管理态）
     *
     * 把模板目录的折算标准发布为 verify-ratios 约定的结构化 JSON（{"models":[...]}），
     * 供厂商 pricing_source_url 指向本站（{站点地址}/api/coding_plan/pricing_source/{code}）：
     * 无需自建定价源即可获得每 6 小时的官方同步 diff——官方改价 → 更新目录（随代码发布）→
     * 校对生成待确认变更；管理员手工改动预置行同样会被检出（漂移监测）。
     * 只读静态数组、无 DB 查询；壳模板（联通/移动）目录暂无折算标准，配置后源状态为「拉取失败」直到录入。
     */
    public function pricingSource(string $code): JsonResponse
    {
        $template = CodingPlanCatalog::templates()[$code] ?? null;
        if ($template === null) {
            return $this->error("官方模板不存在: {$code}", 404);
        }

        $models = collect($template['ratios'])
            ->map(fn (array $r) => array_filter([
                'model' => $r['model'],
                'match_type' => $r['match_type'],
                'cost_mode' => $r['cost_mode'],
                'unit_cost' => (float) $r['unit_cost'],
                'input_rate' => (float) $r['input_rate'],
                'cached_rate' => (float) $r['cached_rate'],
                'output_rate' => (float) $r['output_rate'],
                // 分时段折扣窗口（智谱/DeepSeek 官方口径已预置，其余模板缺省不携带）
                'time_discounts' => $r['time_discounts'] ?? null,
            ], fn ($v) => $v !== null))
            ->values()
            ->all();

        return response()->json([
            'vendor' => $code,
            'unit_name' => $template['unit_name'],
            'verified_at' => $template['verified_at'],
            'source' => 'App\\Services\\CodingPlanCatalog',
            'models' => $models,
        ]);
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
            // 档位标价币种（P7-1 迁移 000010；空 = 继承厂商 currency）
            'currency' => ['nullable', 'string', 'max:8'],
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
            // 档位标价币种（P7-1 迁移 000010；空 = 继承厂商 currency）
            'currency' => ['nullable', 'string', 'max:8'],
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
     * 汇率列表（含回落生效值，便于管理端判断哪些币种靠 Option 兜底）
     * GET /coding_plan/rates
     */
    public function rates(): JsonResponse
    {
        $rows = CurrencyRate::query()->orderBy('code')->get();

        $data = $rows->map(function (CurrencyRate $row) {
            $arr = $row->toArray();
            $arr['rate'] = (float) $row->rate;
            // 该币种当前的生效汇率（表值，或 USD 的 Option 回落）
            $arr['effective_rate'] = CurrencyExchangeService::rate($row->code);

            return $arr;
        })->all();

        // 未维护但平台关心的币种提示（USD 走 Option 兜底；CNY 基准恒 1）
        $hints = [
            ['code' => CurrencyExchangeService::BASE_CURRENCY, 'effective_rate' => 1.0, 'managed' => false],
        ];
        $usdRow = CurrencyRate::query()->where('code', 'USD')->first();
        if ($usdRow === null) {
            $usdFallback = CurrencyExchangeService::rate('USD');
            if ($usdFallback !== null) {
                $hints[] = ['code' => 'USD', 'effective_rate' => $usdFallback, 'managed' => false, 'fallback_option' => CurrencyExchangeService::USD_FALLBACK_OPTION];
            }
        }

        return $this->success(['rates' => $data, 'hints' => $hints]);
    }

    /**
     * 新增/更新汇率（upsert：以币种代码为主键，重复即覆盖）
     * POST /coding_plan/rates
     */
    public function storeRate(Request $request): JsonResponse
    {
        $data = $request->validate([
            // ISO 4217 币种代码（基准 CNY 不需要维护）
            'code' => ['required', 'string', 'max:8', 'not_in:'.CurrencyExchangeService::BASE_CURRENCY],
            // 1 单位该币种 = rate 人民币
            'rate' => ['required', 'numeric', 'min:0.000001', 'max:1000000'],
            'source' => ['nullable', 'string', 'max:16', 'in:manual,api'],
            'remark' => ['nullable', 'string', 'max:255'],
        ]);

        $rate = CurrencyRate::updateOrCreate(
            ['code' => strtoupper($data['code'])],
            [
                'rate' => $data['rate'],
                'source' => $data['source'] ?? CurrencyRate::SOURCE_MANUAL,
                'remark' => $data['remark'] ?? null,
                'updated_at' => time(),
            ]
        );
        CurrencyExchangeService::flushMemo();
        // 折算价进介绍页 offers 聚合（tier.price_cny），汇率变化须即时失效
        Cache::forget('coding_plan_offers');

        return $this->success($rate, '汇率已保存');
    }

    /**
     * 删除汇率（删除后该币种回落默认规则：USD→Option，其余→不可折算）
     * DELETE /coding_plan/rates/{code}
     */
    public function destroyRate(string $code): JsonResponse
    {
        $code = strtoupper($code);
        $deleted = CurrencyRate::query()->where('code', $code)->delete();
        if (! $deleted) {
            return $this->error('汇率不存在', 404);
        }
        CurrencyExchangeService::flushMemo();
        Cache::forget('coding_plan_offers');

        return $this->success(null, '汇率已删除（该币种将按回落规则取值）');
    }

    /**
     * 促销/价格变动/模型退市活动列表（P2-1）
     * GET /coding_plan/promotions?vendor=&state=&all=1
     */
    public function promotions(Request $request): JsonResponse
    {
        $now = time();
        $query = CodingPlanPromotion::query()
            ->when($request->input('vendor'), fn ($q, $v) => $q->where('vendor', $v))
            ->when(! $request->boolean('all'), fn ($q) => $q->enabled())
            ->orderBy('vendor')
            ->orderBy('sort')
            ->orderBy('id');

        $data = $query->get()->map(function (CodingPlanPromotion $row) use ($now) {
            $arr = $row->toArray();
            // 前台展示状态（scheduled/ongoing/expired）与倒计时秒数（ends_at 未公布 → null）
            $arr['state'] = $row->displayState($now);
            $arr['remaining_seconds'] = $row->remainingSeconds($now);

            return $arr;
        })->values()->all();

        return $this->success(['promotions' => $data, 'now' => $now]);
    }

    /**
     * 新增/更新活动（upsert：传 id 即更新，否则新建）
     * POST /coding_plan/promotions
     */
    public function storePromotion(Request $request): JsonResponse
    {
        $data = $request->validate([
            'id' => ['nullable', 'integer', 'min:1'],
            'vendor' => ['required', 'string', 'max:64'],
            'kind' => ['required', 'string', 'in:'.implode(',', CodingPlanPromotion::KINDS)],
            'title' => ['required', 'string', 'max:128'],
            'description' => ['nullable', 'string', 'max:65535'],
            // 折扣乘数 (0,1)：0.8=8 折；仅 discount 类型有意义
            'discount' => ['nullable', 'numeric', 'gt:0', 'lt:1'],
            // Unix 秒；ends_at 传 null/0 = 官方未公布截止（长期有效）
            'starts_at' => ['nullable', 'integer', 'min:0'],
            'ends_at' => ['nullable', 'integer', 'min:0'],
            'source_url' => ['nullable', 'string', 'max:512'],
            'status' => ['nullable', 'integer', 'in:0,1'],
            'remind_days' => ['nullable', 'integer', 'min:0', 'max:90'],
            'sort' => ['nullable', 'integer'],
            'remark' => ['nullable', 'string', 'max:255'],
        ]);

        $endsAt = (int) ($data['ends_at'] ?? 0);
        $startsAt = (int) ($data['starts_at'] ?? 0);
        if ($endsAt > 0 && $startsAt > 0 && $endsAt < $startsAt) {
            return $this->error('ends_at 不能早于 starts_at', 422);
        }

        $payload = [
            'vendor' => strtolower(trim($data['vendor'])),
            'kind' => $data['kind'],
            'title' => $data['title'],
            'description' => $data['description'] ?? null,
            'discount' => $data['discount'] ?? null,
            'starts_at' => $startsAt,
            'ends_at' => $endsAt > 0 ? $endsAt : null, // 0 = 官方未公布
            'source_url' => $data['source_url'] ?? null,
            'status' => $data['status'] ?? CodingPlanPromotion::STATUS_ENABLED,
            'remind_days' => $data['remind_days'] ?? 7,
            'sort' => $data['sort'] ?? 0,
            'remark' => $data['remark'] ?? null,
            'updated_at' => time(),
        ];

        $promotion = isset($data['id'])
            ? CodingPlanPromotion::query()->findOrFail($data['id'])
            : new CodingPlanPromotion;
        $promotion->fill($payload);
        if (! $promotion->exists) {
            $promotion->created_at = time();
        }
        $promotion->save();

        return $this->success($promotion, '活动已保存');
    }

    /**
     * 删除活动
     * DELETE /coding_plan/promotions/{id}
     */
    public function destroyPromotion(int $id): JsonResponse
    {
        $deleted = CodingPlanPromotion::query()->where('id', $id)->delete();
        if (! $deleted) {
            return $this->error('活动不存在', 404);
        }

        return $this->success(null, '活动已删除');
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
     * 公开活动列表（P2-4，介绍页倒计时徽标数据源）。
     * GET /coding_plan/public_promotions
     * 口径：status=1 且未过期（scheduled 预告 + ongoing 进行中均展示，expired 剔除）；
     * 内部字段（status/remind_days/remark）不外露。与管理端 GET /coding_plan/promotions
     * 同 URI 会互相覆盖路由，故公开端点独立命名 public_promotions。
     */
    public function publicPromotions(): JsonResponse
    {
        $now = time();
        $promotions = CodingPlanPromotion::query()
            ->enabled()
            ->orderBy('vendor')
            ->orderBy('sort')
            ->orderBy('id')
            ->get()
            ->filter(fn (CodingPlanPromotion $row) => $row->displayState($now) !== 'expired')
            ->map(fn (CodingPlanPromotion $row) => [
                'id' => $row->id,
                'vendor' => $row->vendor,
                'kind' => $row->kind,
                'title' => $row->title,
                'description' => $row->description,
                'discount' => $row->discount,
                'starts_at' => $row->starts_at,
                'ends_at' => $row->ends_at,
                'source_url' => $row->source_url,
                'state' => $row->displayState($now),
                'remaining_seconds' => $row->remainingSeconds($now),
                'sort' => $row->sort,
            ])
            ->values()
            ->all();

        return $this->success(['promotions' => $promotions, 'now' => $now]);
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
