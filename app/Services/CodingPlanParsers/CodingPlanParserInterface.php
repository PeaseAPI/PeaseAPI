<?php

declare(strict_types=1);

namespace App\Services\CodingPlanParsers;

/**
 * Coding Plan 官方源解析器契约（P1-2）
 *
 * 输入 = 官方页面原始响应体（HTML / Markdown / JSON / 文本），
 * 输出 = 与 VerifyCodingPlanRatios「结构化定价源 JSON」完全同一约定的标准化条目：
 *   {model, match_type, cost_mode, unit_cost, input_rate, cached_rate, output_rate, time_discounts}
 *
 * 单位口径：rate 一律折算为「官方币种 / 1k tokens」（与 coding_plan_model_ratios 一致）；
 * 官方按每百万 tokens 标价时解析器内 ÷1000，按每 token 标价时 ×1000。
 * 基础价取官方「原价」档（如 DeepSeek 高峰价），折扣一律进 time_discounts 窗口，
 * 保证 diff 只在官方真实调价时报警。
 */
interface CodingPlanParserInterface
{
    /**
     * 解析定价页 → 标准化条目列表（key = model|match_type 去重）。
     *
     * @return list<array{model: string, match_type: string, cost_mode: ?string, unit_cost: float|null, input_rate: float|null, cached_rate: float|null, output_rate: float|null, time_discounts: array|null}>
     */
    public function parsePricing(string $body): array;

    /**
     * 解析模型目录页 → 官方模型名列表（供 kind=model_catalog 上下架检测；无目录源返回 []）。
     *
     * @return list<string>
     */
    public function parseCatalog(string $body): array;
}
