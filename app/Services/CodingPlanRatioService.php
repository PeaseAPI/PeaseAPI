<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\CodingPlanAccount;
use App\Models\CodingPlanModelRatio;
use App\Models\CodingPlanRatioCheck;
use App\Models\CodingPlanVendor;
use App\Models\CodingPlanVendorTier;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Schema;

/**
 * Coding Plan 模型折算服务（积分制核心）
 *
 * 将不同厂商、不同模型的用量折算为两级口径：
 *  1. 供应商原生单位（次数/积分，由 coding_plan_model_ratios 比率表决定）
 *  2. 平台积分（统一折算池口径 = 原生单位 × unit_exchange_rate）
 *
 * 比率解析优先级：
 *  exact 全等 > prefix 最长前缀 > 全局默认（unit_cost=1, per_request）
 *
 * 计费模式（账号级 billing_mode）：
 *  - BILLING_MODE_PER_REQUEST（按次）：units = 提交次数（与旧版一致，比率表不参与）
 *  - BILLING_MODE_CREDIT（按积分）：units 由比率表决定（per_request / per_1k_tokens / per_token_parts）
 */
class CodingPlanRatioService
{
    /** 无任何比率匹配时的全局默认单位成本 */
    public const GLOBAL_DEFAULT_UNIT_COST = 1.0;

    /** 比率表缓存时长（秒） */
    protected const CACHE_TTL = 300;

    /** 进程内解析缓存（同请求内多次计算不重复查库） */
    protected array $resolved = [];

    /** @var array<string, Collection> */
    protected array $vendorRatioCache = [];

    /**
     * 解析某厂商某模型的折算比率，未命中返回 null（调用方使用全局默认）。
     */
    public function resolve(string $vendor, string $model): ?CodingPlanModelRatio
    {
        $key = $vendor.'|'.$model;
        if (array_key_exists($key, $this->resolved)) {
            return $this->resolved[$key];
        }

        $ratios = $this->vendorRatios($vendor);

        // 1) exact 全等
        $match = $ratios->first(
            fn (CodingPlanModelRatio $r) => $r->match_type === CodingPlanModelRatio::MATCH_EXACT
                && $r->model === $model
        );

        // 2) prefix 最长前缀优先
        if ($match === null) {
            $match = $ratios
                ->filter(
                    fn (CodingPlanModelRatio $r) => $r->match_type === CodingPlanModelRatio::MATCH_PREFIX
                        && $r->model !== ''
                        && str_starts_with($model, $r->model)
                )
                ->sortByDesc(fn (CodingPlanModelRatio $r) => mb_strlen($r->model))
                ->first();
        }

        return $this->resolved[$key] = $match;
    }

    /**
     * 某厂商全部启用的比率（带缓存，含停用记录的过滤）。
     *
     * @return Collection<int, CodingPlanModelRatio>
     */
    public function vendorRatios(string $vendor): Collection
    {
        if (isset($this->vendorRatioCache[$vendor])) {
            return $this->vendorRatioCache[$vendor];
        }

        $ratios = Cache::remember(
            'coding_plan_ratios:'.$vendor,
            self::CACHE_TTL,
            fn () => CodingPlanModelRatio::query()
                ->where('vendor', $vendor)
                ->where('status', 1)
                ->orderByDesc('sort')
                ->orderBy('id')
                ->get()
        );

        return $this->vendorRatioCache[$vendor] = $ratios;
    }

    /**
     * 账号生效的「供应商单位 → 平台积分」汇率：账号级优先，其次供应商默认，兜底 1。
     * （口径唯一实现在 CodingPlanAccount::effectiveExchangeRate()，此处委托以保持兼容）
     */
    public function exchangeRate(CodingPlanAccount $account): float
    {
        return $account->effectiveExchangeRate();
    }

    /**
     * 清除比率与供应商配置缓存（管理端写操作后调用）。
     *
     * 传 $vendor 时仅清该厂商；不传时清全部厂商
     * （厂商清单取自 vendors 表 ∪ 比率表现存记录）。
     */
    public function flushCache(?string $vendor = null): void
    {
        // 折算数据变更影响公开介绍页/offers 端点的聚合缓存
        Cache::forget('coding_plan_offers');

        if ($vendor !== null) {
            Cache::forget('coding_plan_ratios:'.$vendor);
            Cache::forget('coding_plan_vendor:'.$vendor);
            unset($this->vendorRatioCache[$vendor]);
            foreach (array_keys($this->resolved) as $key) {
                if (str_starts_with($key, $vendor.'|')) {
                    unset($this->resolved[$key]);
                }
            }

            return;
        }

        $codes = CodingPlanVendor::query()->pluck('code')
            ->merge(CodingPlanModelRatio::query()->distinct()->pluck('vendor'))
            ->unique();
        foreach ($codes as $code) {
            Cache::forget('coding_plan_ratios:'.$code);
            Cache::forget('coding_plan_vendor:'.$code);
        }
        $this->vendorRatioCache = [];
        $this->resolved = [];
    }

    /**
     * 计算一次请求在账号池上的消耗。
     *
     * @param  int  $submits  本次请求消耗的提交次数（来自套餐 coding_submits_per_request）
     * @return array{units: float, credits: float, ratio: ?CodingPlanModelRatio, time_window: array|null}
     *                                                                                                    units:   供应商原生单位消耗（已含时段折扣）
     *                                                                                                    credits: 折算后的平台积分
     *                                                                                                    time_window: 命中的时段折扣窗口（null=原价）
     */
    public function calcUsage(
        CodingPlanAccount $account,
        string $model,
        int $promptTokens = 0,
        int $completionTokens = 0,
        int $submits = 1,
        int $cachedTokens = 0
    ): array {
        $submits = max(1, $submits);

        // 按次模式：沿用"每次提交消耗 1 个单位"，比率表不参与（向后兼容）
        if (! $account->isCreditBilling()) {
            $units = round((float) $submits, 4);
            $ratio = $this->resolve($account->vendor, $model);

            return [
                'units' => $units,
                'credits' => $account->toCredits($units),
                'ratio' => $ratio,
                'time_window' => null,
            ];
        }

        // 积分模式：按比率表折算
        $ratio = $this->resolve($account->vendor, $model);
        $costMode = $ratio?->cost_mode ?: CodingPlanModelRatio::COST_PER_REQUEST;
        $unitCost = (float) ($ratio?->unit_cost ?: self::GLOBAL_DEFAULT_UNIT_COST);

        if ($costMode === CodingPlanModelRatio::COST_PER_TOKEN_PARTS) {
            // 分段折算（对齐智谱/阿里云官方公式）：
            // units = (输入 token × input_rate + 缓存命中 × cached_rate + 输出 token × output_rate) / 1000
            // 缓存命中与输入分开计量（智谱与阿里云官方示例均为两段独立相加）。
            // OpenAI 协议下 cached_tokens 是 prompt 子集，命中段会同时计入输入与缓存两段（保守计费，不产生资损）。
            // 三段系数全为 0 视为配置缺失，回退按次计费（防止静默按 0 扣减造成资损）。
            $inputRate = (float) ($ratio->input_rate ?? 0);
            $cachedRate = (float) ($ratio->cached_rate ?? 0);
            $outputRate = (float) ($ratio->output_rate ?? 0);
            if ($inputRate == 0.0 && $cachedRate == 0.0 && $outputRate == 0.0) {
                $units = $unitCost * $submits;
            } else {
                $units = (max(0, $promptTokens) * $inputRate
                    + max(0, $cachedTokens) * $cachedRate
                    + max(0, $completionTokens) * $outputRate) / 1000;
            }
        } elseif ($costMode === CodingPlanModelRatio::COST_PER_1K_TOKENS) {
            $totalTokens = max(0, $promptTokens + $completionTokens);
            $units = ($totalTokens / 1000) * $unitCost;
        } elseif ($costMode === CodingPlanModelRatio::COST_PER_IMAGE
            || $costMode === CodingPlanModelRatio::COST_PER_VIDEO_SECOND) {
            // 图片（豆/张）/视频（豆/秒）：usage 维度为张数/秒数，渠道层尚未捕获（视频为
            // 异步任务，官方预扣=最高清晰度汇率 10 秒冻结、多退少补）——预置期按次保守
            // 兜底（unit_cost × 提交次数）；渠道适配捕获张/秒 usage 后切换真按量口径。
            $units = $unitCost * $submits;
        } else {
            $units = $unitCost * $submits;
        }

        // 官方分时段折扣（智谱非高峰 5 折、DeepSeek 空闲减半、阿里云夜间 5 折等）：
        // 按计费时刻（请求完成时刻）自动命中窗口，units 乘折扣乘数；未配置/未命中 = 原价
        $timeWindow = null;
        if ($ratio !== null) {
            [$discount, $timeWindow] = $ratio->timeDiscountAt();
            if ($discount != 1.0) {
                $units *= $discount;
            }
        }

        $units = round($units, 4);

        return [
            'units' => $units,
            'credits' => $account->toCredits($units),
            'ratio' => $ratio,
            'time_window' => $timeWindow,
        ];
    }

    /**
     * 比率快照（写入流水 meta，便于审计）。
     *
     * @param  array|null  $timeWindow  命中的时段折扣窗口（calcUsage 返回的 time_window）
     * @return array<string, mixed>
     */
    public function snapshot(?CodingPlanModelRatio $ratio, float $units, float $credits, string $billingMode, ?array $timeWindow = null): array
    {
        return [
            'billing_mode' => $billingMode,
            'ratio_id' => $ratio?->id,
            'ratio_model' => $ratio?->model,
            'match_type' => $ratio?->match_type,
            'cost_mode' => $ratio?->cost_mode,
            'unit_cost' => $ratio?->unit_cost,
            'input_rate' => $ratio?->input_rate,
            'cached_rate' => $ratio?->cached_rate,
            'output_rate' => $ratio?->output_rate,
            'time_discount' => $timeWindow['discount'] ?? null,
            'time_window' => $timeWindow['name'] ?? null,
            'units' => $units,
            'credits' => $credits,
        ];
    }

    /**
     * 公开抵扣介绍聚合数据（供应商卡片 + 启用比率 + 最近校对状态）。
     *
     * 供 /api/coding_plan/offers 与介绍页 /coding-plan 共用；调用方负责缓存
     * （约定缓存键 coding_plan_offers，flushCache() 中统一失效）。
     *
     * @return array<string, mixed>
     */
    public function publicOffers(): array
    {
        $staleDays = max(1, (int) OptionService::get('CodingPlanRatioStaleDays', 7));

        if (! Schema::hasTable('coding_plan_vendors') || ! Schema::hasTable('coding_plan_model_ratios')) {
            return ['vendors' => [], 'stale_days' => $staleDays];
        }

        $staleBefore = time() - $staleDays * 86400;

        $vendors = CodingPlanVendor::query()->where('status', 1)->orderBy('sort')->orderBy('id')->get();
        $ratios = CodingPlanModelRatio::query()
            ->where('status', 1)
            ->orderByDesc('sort')
            ->orderBy('id')
            ->get()
            ->groupBy('vendor');

        // 每供应商最近一次校对结果（coding-plan:verify-ratios 每 6 小时写入）
        $checks = CodingPlanRatioCheck::query()
            ->whereIn('vendor', $vendors->pluck('code')->merge($ratios->keys())->unique())
            ->orderByDesc('checked_at')
            ->get()
            ->unique('vendor')
            ->keyBy('vendor');

        $formatRatios = fn (Collection $items) => $items->map(fn (CodingPlanModelRatio $r) => [
            'model' => $r->model,
            'match_type' => $r->match_type,
            'cost_mode' => $r->cost_mode,
            'unit_cost' => (float) $r->unit_cost,
            // 分段折算口径（per_token_parts）下的三段千 token 系数
            'input_rate' => (float) $r->input_rate,
            'cached_rate' => (float) $r->cached_rate,
            'output_rate' => (float) $r->output_rate,
            // 分时段折扣窗口（智谱非高峰 5 折、DeepSeek 空闲减半等官方口径，null=全时段原价）
            'time_discounts' => is_array($r->time_discounts) ? $r->time_discounts : null,
            // 超过核对窗口未人工复核（优惠活动期可能过时）
            'stale' => $r->updated_at > 0 && $r->updated_at < $staleBefore,
            'updated_at' => (int) $r->updated_at,
        ])->values()->all();

        // 官方套餐档位（仅公开展示 status=1），按 vendor_code 分组供卡片渲染。
        // 双列价：price=厂商官方原币价（币种继承 vendors.currency），price_cny=按当前汇率折算
        // 人民币价（不可折算 → null，前端不展示折算列）。
        $tiers = CodingPlanVendorTier::query()
            ->where('status', 1)
            ->orderBy('sort')
            ->orderBy('id')
            ->get()
            ->groupBy('vendor_code');
        $formatTiers = fn (Collection $items, string $currency) => $items->map(function (CodingPlanVendorTier $t) use ($currency) {
            $price = $t->price;

            return [
                'name' => $t->name,
                'price' => $price,
                'currency' => $currency,
                'price_cny' => $price === null
                    ? null
                    : CurrencyExchangeService::convert((float) $price, $currency, 'CNY'),
                'price_note' => $t->price_note,
                'period' => $t->period,
                'quota' => $t->quota,
                'quota_unit' => $t->quota_unit,
                'quota_note' => $t->quota_note,
            ];
        })->values()->all();

        $cards = $vendors->map(function (CodingPlanVendor $vendor) use ($checks, $formatRatios, $formatTiers, $ratios, $tiers) {
            // 官方计价币种（行缺失/字段空按基准 CNY，与计费侧口径一致）
            $currency = CurrencyExchangeService::vendorOfficialCurrency($vendor->code);

            return [
                'code' => $vendor->code,
                'name' => $vendor->name,
                'logo' => $vendor->logo,
                'billing_mode' => (int) $vendor->billing_mode,
                // 产品类型：1=订阅制 Coding Plan / 2=按量 Token Plan（介绍页分组用）
                'plan_kind' => (int) $vendor->plan_kind,
                'unit_name' => $vendor->unit_name,
                'unit_exchange_rate' => (float) $vendor->unit_exchange_rate,
                // 官方计价币种（tier 原币价展示 + 折算 CNY 双列）
                'currency' => $currency,
                'docs_url' => $vendor->docs_url,
                'last_checked_at' => $checks->get($vendor->code)?->checked_at,
                'source_status' => $checks->get($vendor->code)?->source_status ?? CodingPlanRatioCheck::SOURCE_NONE,
                'stale_count' => (int) ($checks->get($vendor->code)?->stale_count ?? 0),
                'change_count' => (int) ($checks->get($vendor->code)?->change_count ?? 0),
                'ratios' => $formatRatios($ratios->get($vendor->code, collect())),
                'tiers' => $formatTiers($tiers->get($vendor->code, collect()), $currency),
            ];
        })->values();

        // 比率表中存在、但未建供应商行的孤儿厂商 → 合成卡片，避免介绍页漏报
        $known = $vendors->pluck('code')->all();
        foreach ($ratios->keys() as $code) {
            if (in_array($code, $known, true)) {
                continue;
            }
            $cards->push([
                'code' => $code,
                'name' => $code,
                'logo' => null,
                'billing_mode' => CodingPlanVendor::BILLING_MODE_PER_REQUEST,
                'plan_kind' => CodingPlanVendor::PLAN_KIND_CODING,
                'unit_name' => '',
                'unit_exchange_rate' => 1.0,
                'currency' => 'CNY',
                'docs_url' => null,
                'last_checked_at' => $checks->get($code)?->checked_at,
                'source_status' => $checks->get($code)?->source_status ?? CodingPlanRatioCheck::SOURCE_NONE,
                'stale_count' => (int) ($checks->get($code)?->stale_count ?? 0),
                'change_count' => (int) ($checks->get($code)?->change_count ?? 0),
                'ratios' => $formatRatios($ratios->get($code, collect())),
                'tiers' => [],
            ]);
        }

        return ['vendors' => $cards->all(), 'stale_days' => $staleDays];
    }
}
