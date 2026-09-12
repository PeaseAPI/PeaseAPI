<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Channel;
use App\Models\CodingPlanAccount;
use App\Models\CodingPlanModelRatio;
use App\Setting\OperationSetting\ModelRouteSetting;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Schema;

/**
 * 成本感知路由服务（P9-1 成本排序器）
 *
 * 同模型多渠道/多厂商候选时，按「每 1k tokens 平台成本」升序排序：
 *   cost_per_1k = 探针单位(三段率/1k 系数或按次 unit_cost) × 时段折扣 × effectiveExchangeRate
 *
 * 口径与计费链严格同源（语义对齐 CodingPlanRatioService::calcUsage）：
 *  - 比率解析 exact > prefix 最长前缀 > 全局默认（unit_cost=1、per_request）
 *  - 时段折扣按 Asia/Shanghai 窗口命中（未配置/未命中 = 原价）
 *  - credits 折算 = 单位 × (账号级 > 供应商默认 > 1) exchangeRate（CodingPlanAccount::effectiveExchangeRate 唯一实现）
 *  - 渠道 → 厂商：coding_plan_accounts.channel_id 关联（与 RelayInfo::applyCodingPlanAccount 同口径，
 *    取首个非停用账号）；无账号关联的普通 API 渠道成本不可计算（null），排序垫底保持 priority 原序
 *  - 币种因子已隐含于 unit_exchange_rate 配置语义（vendor 官方币种价目数值 × 折算系数 = 平台积分），
 *    排序主键取 credits 口径与实际扣减一致、不再额外乘币种汇率（防双重折算）；
 *    另输出 cny_reference（探针单位 × 官方币种 → CNY 市场汇率）仅供观测展示（P9-4）
 *
 * 探针配比：每 1k tokens = 750 输入 + 250 输出（业界典型 75/25；缓存命中不假设 = 保守口径）。
 * per_request 渠道的成本与 token 数无关，探针语义为「1k tokens 预算 ≈ 一次请求」。
 */
class CostRouteService
{
    public const STRATEGY_STATIC = 'static';

    public const STRATEGY_COST_FIRST = 'cost_first';

    /** 每 1k tokens 探针中输入 token 占比 */
    public const PROBE_INPUT_SHARE = 0.75;

    /** 每 1k tokens 探针中输出 token 占比 */
    public const PROBE_OUTPUT_SHARE = 0.25;

    public function __construct(private CodingPlanRatioService $ratios) {}

    /**
     * 当前路由策略（委托 ModelRouteSetting，非法值回退 static）
     */
    public function strategy(): string
    {
        return ModelRouteSetting::strategy();
    }

    /**
     * 排序候选渠道：成本升序在前，成本不可算垫底（P9-1 排序器主入口）
     *
     * @param  Collection<int, Channel>  $channels  候选渠道（ChannelSelectService::candidateChannels）
     * @param  string  $requestModel  用户请求模型名（渠道 model_mapping 在明细内解析）
     * @return array<int, array<string, mixed>> 明细按成本升序；首元素即 cost_first 的选择结果
     */
    public function sortChannelsByCost(Collection $channels, string $requestModel): array
    {
        $details = [];
        foreach ($channels as $channel) {
            $details[] = $this->channelCost($channel, $requestModel);
        }

        usort($details, function (array $a, array $b): int {
            $ca = $a['cost_per_1k'];
            $cb = $b['cost_per_1k'];

            // 成本可算者升序在前；双方均可算且不相等 → 成本升序
            if ($ca !== null && $cb !== null && abs($ca - $cb) > 1e-12) {
                return $ca <=> $cb;
            }

            // 单侧可算 → 可算者在前
            if (($ca !== null) !== ($cb !== null)) {
                return $ca !== null ? -1 : 1;
            }

            // 成本相同或均不可算 → priority 降序 → id 升序（static 语义兜底）
            return [$b['priority'], $a['channel_id']] <=> [$a['priority'], $b['channel_id']];
        });

        return $details;
    }

    /**
     * 计算单渠道「每 1k tokens 平台成本」明细
     *
     * @return array{channel: Channel, channel_id: int, priority: int, vendor: string|null,
     *               account_id: int|null, ratio_id: int|null, probe_model: string,
     *               match_type: string|null, cost_mode: string|null, unit_cost: float|null,
     *               time_discount: float, time_window: string|null, exchange_rate: float|null,
     *               cost_per_1k: float|null, cny_reference: float|null}
     */
    public function channelCost(Channel $channel, string $requestModel): array
    {
        $detail = [
            'channel' => $channel,
            'channel_id' => (int) $channel->id,
            'priority' => (int) ($channel->priority ?? 0),
            'vendor' => null,
            'account_id' => null,
            'ratio_id' => null,
            'probe_model' => $requestModel,
            'match_type' => null,
            'cost_mode' => null,
            'unit_cost' => null,
            'time_discount' => 1.0,
            'time_window' => null,
            'exchange_rate' => null,
            'cost_per_1k' => null,
            'cny_reference' => null,
        ];

        // 渠道 → 账号池：与 RelayInfo::applyCodingPlanAccount 同口径（首个非停用账号定厂商）
        $account = $this->accountForChannel((int) $channel->id);
        if ($account === null) {
            return $detail; // 普通 API 渠道：成本不可计算，排序垫底
        }

        $vendor = (string) $account->vendor;
        $detail['vendor'] = $vendor;
        $detail['account_id'] = (int) $account->id;

        // 模型映射（与计费链 RelayInfo 一致：比率解析用映射后上游模型名）
        $mapped = $channel->getModelMapping($requestModel);
        $probeModel = ($mapped !== null && $mapped !== '') ? $mapped : $requestModel;
        $detail['probe_model'] = $probeModel;

        $ratio = $this->ratios->resolve($vendor, $probeModel);
        if ($ratio === null) {
            // 全局默认口径（对齐 calcUsage：unit_cost=1、per_request）
            $detail['cost_mode'] = CodingPlanModelRatio::COST_PER_REQUEST;
        } else {
            $detail['ratio_id'] = (int) $ratio->id;
            $detail['match_type'] = $ratio->match_type;
            $detail['cost_mode'] = $ratio->cost_mode;
            $detail['unit_cost'] = (float) $ratio->unit_cost;
        }

        $costMode = $detail['cost_mode'] ?? CodingPlanModelRatio::COST_PER_REQUEST;
        $unitCost = $ratio !== null && (float) $ratio->unit_cost > 0
            ? (float) $ratio->unit_cost
            : CodingPlanRatioService::GLOBAL_DEFAULT_UNIT_COST;

        $probeUnits = match ($costMode) {
            CodingPlanModelRatio::COST_PER_TOKEN_PARTS => $this->probeUnitsForParts($ratio, $unitCost),
            CodingPlanModelRatio::COST_PER_1K_TOKENS => $unitCost,
            default => $unitCost,
        };

        [$discount, $window] = $ratio?->timeDiscountAt() ?? [1.0, null];
        $detail['time_discount'] = $discount;
        $detail['time_window'] = $window['name'] ?? null;

        $exchangeRate = $account->effectiveExchangeRate();
        $detail['exchange_rate'] = $exchangeRate;
        $detail['cost_per_1k'] = round($probeUnits * $discount * $exchangeRate, 6);

        // 观测参考：官方币种市场价（不参与排序；基准 CNY 无需折算）
        $currency = CurrencyExchangeService::vendorOfficialCurrency($vendor);
        if ($currency !== CurrencyExchangeService::BASE_CURRENCY) {
            $fxRate = CurrencyExchangeService::rate($currency);
            if ($fxRate !== null) {
                $detail['cny_reference'] = round($probeUnits * $discount * $fxRate, 6);
            }
        }

        return $detail;
    }

    /**
     * per_token_parts 探针单位：750 输入 + 250 输出（缓存命中不假设，保守口径）
     *
     * = (750 × input_rate + 250 × output_rate) / 1000 = 0.75 × input_rate + 0.25 × output_rate；
     * 三段全 0 视为配置缺失回退按次口径（对齐 calcUsage 防资损回退）。
     */
    protected function probeUnitsForParts(?CodingPlanModelRatio $ratio, float $unitCost): float
    {
        $inputRate = (float) ($ratio?->input_rate ?? 0);
        $cachedRate = (float) ($ratio?->cached_rate ?? 0);
        $outputRate = (float) ($ratio?->output_rate ?? 0);

        if ($inputRate == 0.0 && $cachedRate == 0.0 && $outputRate == 0.0) {
            return $unitCost;
        }

        return self::PROBE_INPUT_SHARE * $inputRate + self::PROBE_OUTPUT_SHARE * $outputRate;
    }

    /**
     * 渠道关联的账号池账号（首个非停用；orderBy id 与 RelayInfo first() 自然序一致）
     */
    protected function accountForChannel(int $channelId): ?CodingPlanAccount
    {
        if (! Schema::hasTable('coding_plan_accounts')) {
            return null; // 极老部署缺表：全部退化为成本不可算，排序等价 static
        }

        return CodingPlanAccount::query()
            ->where('channel_id', $channelId)
            ->where('status', '!=', CodingPlanAccount::STATUS_DISABLED)
            ->orderBy('id')
            ->first();
    }
}
