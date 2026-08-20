<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Ability;
use App\Models\Pricing;
use Illuminate\Support\Facades\Cache;

/**
 * 文本配额服务 - Token计费计算
 */
class TextQuotaService
{
    /**
     * 计算请求费用（基于Token数量和模型倍率）
     */
    public function calculatePromptCost(int $promptTokens, string $model, string $group = 'default'): int
    {
        $modelRatio = $this->getModelRatio($model);
        $groupRatio = $this->getGroupRatio($group);

        return (int) ($promptTokens * $modelRatio * $groupRatio);
    }

    /**
     * 计算补全费用（基于Token数量和模型倍率）
     */
    public function calculateCompletionCost(int $completionTokens, string $model, string $group = 'default'): int
    {
        $modelRatio = $this->getModelRatio($model);
        $groupRatio = $this->getGroupRatio($group);
        $completionRatio = $this->getCompletionRatio($model);

        return (int) ($completionTokens * $modelRatio * $completionRatio * $groupRatio);
    }

    /**
     * 计算总费用（输入+输出）
     */
    public function calculateTotalCost(int $promptTokens, int $completionTokens, string $model, string $group = 'default'): int
    {
        return $this->calculatePromptCost($promptTokens, $model, $group)
             + $this->calculateCompletionCost($completionTokens, $model, $group);
    }

    /**
     * 获取模型倍率
     */
    public function getModelRatio(string $model): float
    {
        $cacheKey = "model_ratio:{$model}";

        return Cache::remember($cacheKey, 3600, function () use ($model) {
            $pricing = Pricing::where('model_name', $model)->first();

            return $pricing ? (float) $pricing->input_ratio : 1.0;
        });
    }

    /**
     * 获取分组倍率
     */
    public function getGroupRatio(string $group): float
    {
        $cacheKey = "group_ratio:{$group}";

        return Cache::remember($cacheKey, 3600, function () use ($group) {
            // 从配置或数据库获取分组倍率
            $ratio = config("pease-api.billing.group_ratios.{$group}", 1.0);

            return (float) $ratio;
        });
    }

    /**
     * 获取补全倍率（输出/输入比例）
     */
    public function getCompletionRatio(string $model): float
    {
        $cacheKey = "completion_ratio:{$model}";

        return Cache::remember($cacheKey, 3600, function () use ($model) {
            $pricing = Pricing::where('model_name', $model)->first();

            return $pricing ? (float) $pricing->output_ratio : 1.0;
        });
    }

    /**
     * 获取模型价格（每1K Token）
     */
    public function getModelPrice(string $model): array
    {
        $pricing = Pricing::where('model_name', $model)->first();

        if (! $pricing) {
            // 默认价格
            return [
                'input' => 0.002,
                'output' => 0.002,
            ];
        }

        return [
            'input' => (float) $pricing->input_price,
            'output' => (float) $pricing->output_price,
        ];
    }

    /**
     * 计算缓存费用（Prompt Cache）
     */
    public function calculateCacheCost(int $cachedTokens, string $model): int
    {
        $cacheRatio = config('pease-api.billing.cache_ratio', 0.1); // 默认缓存折扣 10%
        $modelRatio = $this->getModelRatio($model);

        return (int) ($cachedTokens * $modelRatio * $cacheRatio);
    }

    /**
     * 批量计算多个模型的费用
     */
    public function batchCalculateCost(array $models): array
    {
        $results = [];

        foreach ($models as $model) {
            $results[$model] = [
                'model_ratio' => $this->getModelRatio($model),
                'completion_ratio' => $this->getCompletionRatio($model),
                'price' => $this->getModelPrice($model),
            ];
        }

        return $results;
    }

    /**
     * 获取支持的所有模型
     */
    public function getSupportedModels(): array
    {
        return Pricing::pluck('model_name')->toArray();
    }

    /**
     * 验证模型是否支持
     */
    public function isModelSupported(string $model): bool
    {
        return Pricing::where('model_name', $model)->exists();
    }

    /**
     * 获取模型的分组
     */
    public function getModelGroup(string $model): string
    {
        $ability = Ability::where('model', $model)->first();

        return $ability ? $ability->group : 'default';
    }

    /**
     * 获取分组下的所有模型
     */
    public function getModelsByGroup(string $group): array
    {
        return Ability::where('group', $group)
            ->where('enabled', true)
            ->pluck('model')
            ->toArray();
    }
}
