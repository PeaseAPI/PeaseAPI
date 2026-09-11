<?php

declare(strict_types=1);

namespace App\Services\CodingPlanParsers;

/**
 * MiniMax 按量定价解析器（https://platform.minimax.io/docs/guides/pricing-paygo.md）。
 *
 * 源定位（2026-09-12）：platform.minimaxi.com（国内，¥）与 platform.minimax.io（国际，$）
 * 均支持 llms.txt 索引 + .md 直取；注册国际站保证 USD 口径与 DB rate 字段一致。
 * 页面结构（paygo.md）：
 *  - LLM 区块 <Tabs>：Standard / Priority* 两个 Tab（Priority 为 service_tier=priority 的
 *    1.5x 条件价 → 整段剥离，只保留 Standard）；
 *  - M3 行内分档「≤ 512k input tokens」与「> 512k input tokens」两档 → 只取首档（同 xai 策略）；
 *  - M2.7 系列独立表（4 列：Input / Output / Prompt caching Read / Write，Write 不入库）；
 *  - <Accordion title=\"Legacy Models\"> 内 M2.5/M2.1 系列已退役 → 整段剥离；
 *  - 划线促销「~~\$0.60~~ \$0.30 / M tokens」为 Permanent 50% off 长期降价 → 取实价（后值）。
 *
 * 口径决策：单位 ÷1000（$/1M → $/1k），cost_mode=per_token_parts（缓存/输入/输出三率）；
 * Token Plan 订阅套餐页（pricing-token-plan.md）为套餐档位，归 P2-1，不在本源。
 */
class MiniMaxParser extends AbstractCodingPlanParser
{
    public function parsePricing(string $body): array
    {
        // .md 正文含 JSON 风格转义（\"、\$），先归一化（引号供 Tab/Accordion 剥离正则使用）
        $body = str_replace(['\\"', '\\$'], ['"', '$'], $body);
        // 条件价（Priority Tab）与退役模型（Legacy Accordion）整段剥离，防止同模型双价入 diff
        $body = preg_replace('/<Tab title="Priority\*?">.*?<\/Tab>/s', '', $body) ?? $body;
        $body = preg_replace('/<Accordion title="Legacy Models">.*?<\/Accordion>/s', '', $body) ?? $body;

        $entries = [];
        foreach ($this->markdownTables($body) as $table) {
            $header = array_map(fn (string $cell): string => mb_strtolower(trim($cell)), $table['header']);
            $inputIdx = $cachedIdx = $outputIdx = null;
            foreach ($header as $idx => $cell) {
                if ($cell === 'input') {
                    $inputIdx = $idx;
                } elseif (str_starts_with($cell, 'prompt caching read')) {
                    $cachedIdx = $idx;
                } elseif ($cell === 'output') {
                    $outputIdx = $idx;
                }
            }
            if ($inputIdx === null || $outputIdx === null) {
                continue; // 非价格表（语音/视频资源包等）
            }

            foreach ($table['rows'] as $row) {
                $modelCell = $row[0] ?? '';
                if (str_contains($modelCell, '> 512k') || str_contains($modelCell, '＞ 512k')) {
                    continue; // 长输入第二档（条件价），只保留 ≤ 512k 首档
                }
                // 模型名在粗体标记里（**MiniMax-M3**<br />≤ 512k input tokens <span>Permanent 50% off</span>）
                $model = preg_match('/\*\*([^*]+)\*\*/', $modelCell, $m)
                    ? $this->modelName($m[1])
                    : $this->modelName($this->cleanText($modelCell));
                if ($model === null || ! str_starts_with($model, 'minimax-')) {
                    continue;
                }
                $entries[] = [
                    'model' => $model,
                    'match_type' => 'exact',
                    'cost_mode' => 'per_token_parts',
                    'unit_cost' => null,
                    'input_rate' => $this->usdPerK($row[$inputIdx] ?? null),
                    'cached_rate' => $cachedIdx !== null ? $this->usdPerK($row[$cachedIdx] ?? null) : null,
                    'output_rate' => $this->usdPerK($row[$outputIdx] ?? null),
                    'time_discounts' => null,
                ];
            }
        }

        return $entries;
    }

    public function parseCatalog(string $body): array
    {
        return []; // MiniMax 无独立模型目录页
    }

    /**
     * $/1M 单元格 → $/1k（÷1000）；先剥 ~~划线~~ 促销原价再取值（实价=后值）。
     */
    protected function usdPerK(?string $raw): ?float
    {
        if ($raw !== null) {
            $raw = preg_replace('/~~[^~]*~~/', '', $raw) ?? $raw;
        }
        $value = $this->number($raw);

        return $value === null ? null : $value / 1000;
    }
}
