<?php

declare(strict_types=1);

namespace App\Services\CodingPlanParsers;

/**
 * xAI Grok 模型定价解析器（https://docs.x.ai/developers/models.md，Mintlify .md 直取）。
 *
 * 页面结构（2026-09-12 .md 样本核实，4.4KB 纯 Markdown）：
 *  - 「Text API Pricing」表：Model | Context | Input / 1M tokens | Cached input / 1M tokens | Output / 1M tokens；
 *    长上下文分档模型占两行：「grok-4.6 (< 200k prompt tokens)」与「grok-4.6 (≥ 200k prompt tokens)」，
 *    官方说明 prompt 达到阈值后整个请求按高档计费；
 *  - 「Imagine Pricing」（$/image、$/sec）与「Voice Pricing」（$/min、$/hr、$/1M chars）为按次计价表，
 *    与 Google 图价同理不产出 token 条目（非 token 口径归 P2 promotions）。
 *
 * 口径决策：
 *  - 长上下文只取首档（< 200k）——≥ 200k 档是同一模型的条件价，跳过避免同模型双行刷 changed；
 *  - 单位 ÷1000（$/1M → $/1k），cost_mode=per_token_parts（输入/缓存/输出三率）。
 */
class XaiMarkdownParser extends AbstractCodingPlanParser
{
    public function parsePricing(string $body): array
    {
        $entries = [];
        foreach ($this->markdownTables($body) as $table) {
            $header = array_map(fn (string $cell): string => mb_strtolower(trim($cell)), $table['header']);
            $inputIdx = $cachedIdx = $outputIdx = null;
            foreach ($header as $idx => $cell) {
                if ($cell === 'input / 1m tokens') {
                    $inputIdx = $idx;
                } elseif (str_starts_with($cell, 'cached input')) {
                    $cachedIdx = $idx;
                } elseif ($cell === 'output / 1m tokens') {
                    $outputIdx = $idx;
                }
            }
            if ($inputIdx === null || $outputIdx === null) {
                continue; // Imagine / Voice 等按次计价表
            }

            foreach ($table['rows'] as $row) {
                $modelCell = $row[0] ?? '';
                if (str_contains($modelCell, '≥ 200k')) {
                    continue; // 长上下文档（条件价），只保留首档
                }
                $model = $this->modelName($modelCell);
                if ($model === null || ! str_starts_with($model, 'grok')) {
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
        // 目录口径=token 计价表内的在售模型（与 parsePricing 同表筛选）：Imagine/Voice
        // 按次计价表不产出（库内无对应行，收进目录只会制造 missing 噪音）；
        // 长上下文 (< 200k)/(≥ 200k) 档位行剥括号后归并同名（modelName + 去重）
        $models = [];
        foreach ($this->markdownTables($body) as $table) {
            $header = array_map(fn (string $cell): string => mb_strtolower(trim($cell)), $table['header']);
            $isTokenTable = in_array('input / 1m tokens', $header, true)
                && in_array('output / 1m tokens', $header, true);
            if (! $isTokenTable) {
                continue;
            }
            foreach ($table['rows'] as $row) {
                $model = $this->modelName($row[0] ?? '');
                if ($model === null || ! str_starts_with($model, 'grok')) {
                    continue;
                }
                $models[$model] = true;
            }
        }

        return array_keys($models);
    }

    /**
     * $/1M 单元格 → $/1k（÷1000）；'-' 或空 → null。
     */
    protected function usdPerK(?string $raw): ?float
    {
        $value = $this->number($raw);

        return $value === null ? null : $value / 1000;
    }
}
