<?php

declare(strict_types=1);

namespace App\Services\CodingPlanParsers;

/**
 * Anthropic Claude API 定价解析器（https://docs.anthropic.com/en/docs/about-claude/pricing）。
 *
 * 页面结构（2026-09-12 快照核实，Next.js SSR，日本代理出口可直抓）：
 *  - API 定价行：Model | Base input tokens | 5m cache writes | 1h cache writes |
 *    Cache hits and refreshes | Output tokens，每行「$10 / MTok」（缓存命中列带脚注数字「$0.25 / MTok1」）；
 *  - 表格由 div+CSS 渲染（无 <table> 标签），按 <tr> 平铺扫描、以「Model」表头行重置列定位；
 *  - 页面还有 CCU 计费说明行（Concept|Details）等，靠 modelName 校验自然跳过。
 *
 * 口径决策：
 *  - 显示名转模型 id：「Claude Opus 4.6」→ claude-opus-4.6（括号注释已由基类剥离）；
 *  - 5m/1h cache writes（写入价）暂无标准化字段，忽略——只输出 input/cached(命中)/output；
 *  - 单位 ÷1000（$/1M → $/1k），cost_mode=per_token_parts；retired 行也解析输出
 *    （库内无则报 new，由人工确认是否上架 Bedrock/Vertex 渠道）。
 */
class AnthropicParser extends AbstractCodingPlanParser
{
    public function parsePricing(string $body): array
    {
        $entries = [];
        $output = [];
        $inputCol = $cachedCol = $outputCol = null;
        foreach ($this->tableRows($body) as $cells) {
            $first = $cells[0] ?? '';

            // 表头行（div 表格无 <table>，按「Model」首列重置列定位）
            if ($first === 'Model' && count($cells) >= 3) {
                $inputCol = $cachedCol = $outputCol = null;
                if (preg_match('/batch/iu', implode(' ', $cells))) {
                    continue; // Batch 半价表：非基础价（与 OpenAI Batch 列同口径），整表跳过
                }
                foreach ($cells as $index => $title) {
                    if (preg_match('/Base input/iu', $title)) {
                        $inputCol = $index;
                    } elseif (preg_match('/Cache hits/iu', $title)) {
                        $cachedCol = $index;
                    } elseif (preg_match('/Output/iu', $title) && ! preg_match('/write/iu', $title)) {
                        $outputCol = $index;
                    }
                }
                if ($inputCol === null && $outputCol === null) {
                    $inputCol = $cachedCol = $outputCol = null; // 非定价表头，复位等待下一个
                }

                continue;
            }

            // 数据行（未定位到列 / 首列非模型名 → 跳过：CCU 说明、小节标题等）
            if ($inputCol === null && $outputCol === null) {
                continue;
            }
            $model = $this->displayToModelId($first);
            if ($model === null) {
                continue;
            }
            $entries[] = [
                'model' => $model,
                'match_type' => 'exact',
                'cost_mode' => 'per_token_parts',
                'unit_cost' => null,
                'input_rate' => $this->mtokPrice($cells[$inputCol] ?? null),
                'cached_rate' => $this->mtokPrice($cells[$cachedCol] ?? null),
                'output_rate' => $this->mtokPrice($cells[$outputCol] ?? null),
                'time_discounts' => null,
            ];
        }

        // 页面含主定价表之外的重复表（Batch 半价、1M 长上下文合并名行、CCU 说明）；
        // 主表在文档最前，同模型以首条为准，防后表覆盖
        $unique = [];
        foreach ($entries as $entry) {
            if (! isset($unique[$entry['model']])) {
                $unique[$entry['model']] = true;
                $output[] = $entry;
            }
        }

        return $output;
    }

    public function parseCatalog(string $body): array
    {
        // 定价页每行即一个在售模型；目录源（docs/models 页）待接
        return [];
    }

    /**
     * 显示名 → 官方模型 id：剥括号注释 → 小写 → 空格转连字符 → 白名单校验。
     * 「Claude Opus 4.6 (limited availability)」→ claude-opus-4.6；非模型行（CCU 说明表
     * 的 Concept/Conversion 等）无 claude- 前缀 → null。
     * 注意：不能走基类 modelName()——其白名单不含空格，显示名会被整体拒绝。
     */
    protected function displayToModelId(?string $raw): ?string
    {
        $raw = trim((string) $raw);
        if ($raw === '') {
            return null;
        }
        $raw = preg_replace('/[（(][^（）()]*[)）]/u', '', $raw) ?? $raw; // (limited availability) / (retired ...)
        $id = str_replace(' ', '-', mb_strtolower(trim($raw)));
        if (! preg_match('/^claude-[a-z0-9.-]{1,96}$/', $id)) {
            return null;
        }

        return $id;
    }

    /**
     * 「$10 / MTok1」→ 0.01（$/1k；脚注数字随首个浮点截断，MTok→1k = ÷1000）。
     */
    protected function mtokPrice(?string $raw): ?float
    {
        $value = $this->number($raw);

        return $value === null ? null : $value / 1000;
    }
}
