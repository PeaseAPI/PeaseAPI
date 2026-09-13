<?php

declare(strict_types=1);

namespace App\Services\CodingPlanParsers;

/**
 * Anthropic Claude API 定价解析器（platform.claude.com .md 直取，P3-6b）。
 *
 * 源演变（2026-09-13 核实）：docs.anthropic.com 301 → platform.claude.com（域名迁移），
 * `/en/docs/about-claude/pricing.md` 官方 Markdown 出口可用（与 xai/zhipu 同模式）；
 * 直连被区域封锁（301 → claude.com/app-unavailable-in-region），注册表 proxy=true
 * 走 PEASE_API_HTTP_PROXY 后 200/45KB。
 *
 * Markdown 结构（front-matter 后正文）：
 *  - Model pricing 主表：Model | Base input tokens | 5m cache writes | 1h cache writes |
 *    Cache hits and refreshes | Output tokens，价格形如「$10 / MTok」「$0.25 / MTok1」（脚注数字）；
 *  - retired 行链接文本标注（[retired, except on Bedrock and Google Cloud](...)）→ 整行拒收
 *    （第一方 API 已不可用，收录只会刷 new 噪音；P1-6 目录口径同步收紧为在售集合）；
 *  - Cloud platform pricing（Bedrock/Vertex 官方价外链）、CCU Concept 表（Concept|Details）、
 *    Feature-specific pricing（Web search 等按次）靠表头/模型名校验自然跳过。
 *
 * 口径决策：
 *  - 显示名转模型 id：「Claude Opus 4.6」→ claude-opus-4.6（markdown 链接与括号注释先剥）；
 *  - 5m/1h cache writes（写入价）暂无标准化字段，忽略——只输出 input/cached(命中)/output；
 *  - 单位 ÷1000（$/1M → $/1k），cost_mode=per_token_parts。
 */
class AnthropicParser extends AbstractCodingPlanParser
{
    public function parsePricing(string $body): array
    {
        $entries = [];
        foreach ($this->markdownTables($body) as $table) {
            $header = $table['header'];
            if (($header[0] ?? '') !== 'Model' || count($header) < 3) {
                continue; // CCU Concept 表（Concept|Details）、Feature 表等非模型定价表
            }
            if (preg_match('/batch/iu', implode(' ', $header))) {
                continue; // Batch 半价表：非基础价（与 OpenAI Batch 列同口径），整表跳过
            }

            $inputCol = $cachedCol = $outputCol = null;
            foreach ($header as $index => $title) {
                if (preg_match('/Base input/iu', $title)) {
                    $inputCol = $index;
                } elseif (preg_match('/Cache hits/iu', $title)) {
                    $cachedCol = $index;
                } elseif (preg_match('/Output/iu', $title) && ! preg_match('/write/iu', $title)) {
                    $outputCol = $index;
                }
            }
            if ($inputCol === null || $outputCol === null) {
                continue; // 未定位到列（非定价主表）
            }

            foreach ($table['rows'] as $row) {
                $raw = $row[0] ?? '';
                // retired 行拒收：链接文本标注官方已退役（Bedrock/Vertex 例外亦非第一方 API）
                if (preg_match('/retired/iu', $raw)) {
                    continue;
                }
                $model = $this->displayToModelId($raw);
                if ($model === null) {
                    continue;
                }
                $entries[] = [
                    'model' => $model,
                    'match_type' => 'exact',
                    'cost_mode' => 'per_token_parts',
                    'unit_cost' => null,
                    'input_rate' => $this->mtokPrice($row[$inputCol] ?? null),
                    'cached_rate' => $this->mtokPrice($row[$cachedCol] ?? null),
                    'output_rate' => $this->mtokPrice($row[$outputCol] ?? null),
                    'time_discounts' => null,
                ];
            }
        }

        // 同模型以首条为准（防 1M 长上下文合并名行/重复表覆盖）
        $unique = [];
        $output = [];
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
        // 目录口径=主表在售模型（retired 行拒收，与 parsePricing 同集合——P1-6 口径收紧：
        // retired 不算在售，库内已配行若官方退役应走 missing（下架）而非目录保留）
        $models = [];
        foreach ($this->parsePricing($body) as $entry) {
            $models[$entry['model']] = true;
        }

        return array_keys($models);
    }

    /**
     * 显示名 → 官方模型 id：剥 markdown 链接与括号注释 → 小写 → 空格转连字符 → 白名单校验。
     * 「Claude Mythos 5.1 ([limited availability](https://...))」→ claude-mythos-5.1；
     * 非模型行（CCU 说明表的 Concept/Conversion 等）无 claude- 前缀 → null。
     * 注意：不能走基类 modelName()——其白名单不含空格，显示名会被整体拒绝。
     */
    protected function displayToModelId(?string $raw): ?string
    {
        $raw = trim((string) $raw);
        if ($raw === '') {
            return null;
        }
        // markdown 链接整体剥除（含链接文本，防 [limited availability] 残留破白名单）
        $raw = preg_replace('/\[[^\[\]]*\]\([^()]*\)/u', '', $raw) ?? $raw;
        $raw = preg_replace('/[（(][^（）()]*[)）]/u', '', $raw) ?? $raw; // 剩余裸括号注释
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
