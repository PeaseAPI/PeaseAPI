<?php

declare(strict_types=1);

namespace App\Services;

/**
 * 文本配额服务 - Token 计费计算
 *
 * 倍率来源为 OptionService 的 JSON 配置（对标 new-api）：
 * - ModelRatio       {model: ratio}          输入倍率
 * - CompletionRatio  {model: ratio}          输出/输入倍率
 * - GroupRatio       {group: ratio}          分组倍率
 * - ModelPrice       {model: usd_per_call}   按次固定价（优先于倍率）
 * - CacheRatio       {model: ratio}          缓存折扣（cached_tokens 命中部分按此倍率计费；未配置 = 不打折）
 * - QuotaPerUnit                             1 美元对应额度（默认 500000）
 *
 * 倍率模式：quota = (prompt × modelRatio + completion × modelRatio × completionRatio) × groupRatio
 * 缓存模式：quota = ((prompt − cached) × modelRatio + cached × modelRatio × cacheRatio
 *           + completion × modelRatio × completionRatio) × groupRatio
 * 固定价模式：quota = ModelPrice[model] × QuotaPerUnit
 */
class TextQuotaService
{
    /**
     * 计算输入费用（基于 Token 数量与倍率）
     */
    public function calculatePromptCost(int $promptTokens, string $model, string $group = 'default'): int
    {
        return (int) round($promptTokens * $this->getModelRatio($model) * $this->getGroupRatio($group));
    }

    /**
     * 计算补全费用（基于 Token 数量与倍率）
     */
    public function calculateCompletionCost(int $completionTokens, string $model, string $group = 'default'): int
    {
        return (int) round(
            $completionTokens * $this->getModelRatio($model) * $this->getCompletionRatio($model) * $this->getGroupRatio($group)
        );
    }

    /**
     * 计算总费用（输入 + 输出；ModelPrice 覆盖时按次计费）
     */
    public function calculateTotalCost(int $promptTokens, int $completionTokens, string $model, string $group = 'default'): int
    {
        $fixedPrice = $this->getModelPrice($model);
        if ($fixedPrice > 0) {
            // 按次固定价：单次调用固定额度，与 token 数无关
            return (int) round($fixedPrice * $this->getQuotaPerUnit());
        }

        return $this->calculatePromptCost($promptTokens, $model, $group)
             + $this->calculateCompletionCost($completionTokens, $model, $group);
    }

    /**
     * 计算总费用（含缓存折扣；BillingService 计费入口）
     *
     * cachedTokens 为命中缓存的输入 token 数，该部分按 modelRatio × cacheRatio 计费，
     * 其余输入按全价。未配置 CacheRatio 的模型 cacheRatio 视为 1（不打折，行为与旧版一致）。
     * ModelPrice 固定价优先，与 token 数无关。
     */
    public function calculateTotalCostWithCache(
        int $promptTokens,
        int $cachedTokens,
        int $completionTokens,
        string $model,
        string $group = 'default'
    ): int {
        $fixedPrice = $this->getModelPrice($model);
        if ($fixedPrice > 0) {
            // 按次固定价：单次调用固定额度，与 token 数无关
            return (int) round($fixedPrice * $this->getQuotaPerUnit());
        }

        $modelRatio = $this->getModelRatio($model);
        $cacheRatio = $this->getCacheRatio($model);
        if ($cacheRatio <= 0) {
            $cacheRatio = 1.0; // 未配置 = 缓存不打折
        }

        $promptTokens = max(0, $promptTokens);
        $cached = min(max(0, $cachedTokens), $promptTokens);
        $uncached = $promptTokens - $cached;

        return (int) round(
            ($uncached * $modelRatio
                + $cached * $modelRatio * $cacheRatio
                + max(0, $completionTokens) * $modelRatio * $this->getCompletionRatio($model)
            ) * $this->getGroupRatio($group)
        );
    }

    /**
     * 获取模型倍率（未知模型回落 1.0）
     */
    public function getModelRatio(string $model): float
    {
        $ratios = OptionService::get('ModelRatio', []);

        return is_array($ratios) ? (float) ($ratios[$model] ?? 1.0) : 1.0;
    }

    /**
     * 获取分组倍率（未知分组回落 default 组，再回落 1.0）
     */
    public function getGroupRatio(string $group): float
    {
        $ratios = OptionService::get('GroupRatio', []);
        if (! is_array($ratios)) {
            return 1.0;
        }

        return (float) ($ratios[$group] ?? $ratios['default'] ?? 1.0);
    }

    /**
     * 获取补全倍率（输出/输入比例，未知模型回落 1.0）
     */
    public function getCompletionRatio(string $model): float
    {
        $ratios = OptionService::get('CompletionRatio', []);

        return is_array($ratios) ? (float) ($ratios[$model] ?? 1.0) : 1.0;
    }

    /**
     * 获取模型按次固定价（USD/次；未配置返回 0.0 表示走倍率计费）
     */
    public function getModelPrice(string $model): float
    {
        $prices = OptionService::get('ModelPrice', []);

        return is_array($prices) ? (float) ($prices[$model] ?? 0.0) : 0.0;
    }

    /**
     * 获取缓存倍率（cache token 计费比例；未配置返回 0.0 表示不计缓存费）
     */
    public function getCacheRatio(string $model): float
    {
        $ratios = OptionService::get('CacheRatio', []);

        return is_array($ratios) ? (float) ($ratios[$model] ?? 0.0) : 0.0;
    }

    /**
     * 计算缓存费用（Prompt Cache；cachedTokens 为命中缓存的输入 token 数）
     */
    public function calculateCacheCost(int $cachedTokens, string $model, string $group = 'default'): int
    {
        $cacheRatio = $this->getCacheRatio($model);
        if ($cacheRatio <= 0) {
            return 0;
        }

        return (int) round($cachedTokens * $this->getModelRatio($model) * $cacheRatio * $this->getGroupRatio($group));
    }

    /**
     * 1 美元对应的额度单位（全站换算基准）
     */
    public function getQuotaPerUnit(): int
    {
        return (int) OptionService::get('QuotaPerUnit', 500000);
    }
}
