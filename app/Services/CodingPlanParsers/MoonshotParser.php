<?php

declare(strict_types=1);

namespace App\Services\CodingPlanParsers;

/**
 * Kimi / Moonshot 模型定价解析器（https://platform.kimi.ai/docs/pricing/chat.md）。
 *
 * 源定位（2026-09-12）：platform.moonshot.cn 已 301 迁移至 platform.kimi.com（Kimi 开放平台），
 * 文档站支持 llms.txt 索引 + .md 直取；.md 页面的表格是 JSX 组件 <DocTable columns=… rows=…>，
 * rows 为 JS 数组字面量，价格元素两种形态：
 *  - 国际站（美元，本解析器的注册源）：<>{\"$\"}0.30</>
 *  - 中文站（人民币，不注册）：\"¥2.00\" 纯字符串
 * 列序：模型 | 计费单位 | 输入价格(缓存命中) | 输入价格(缓存未命中) | 输出价格 | 上下文窗口。
 * 注意与 DeepSeek/OpenAI 相反：这里第一列价格 = 缓存命中价（cached_rate），
 * 第二列 = 缓存未命中价（即普通 input_rate）。
 *
 * 口径决策：
 *  - pricing/batch.md（批量半价）与 pricing/tools.md（联网搜索按次）为独立页未注册，
 *    batch 是另一计费通道（同 DeepSeek 只取标准通道策略），tools 非 token 口径；
 *  - 单位 ÷1000（$/1M → $/1k），cost_mode=per_token_parts（缓存/输入/输出三率）。
 */
class MoonshotParser extends AbstractCodingPlanParser
{
    public function parsePricing(string $body): array
    {
        // .md 的 rows 行含 JSON 风格转义（\"、\$），先归一化为裸字符再匹配
        $body = str_replace(['\\"', '\\$'], ['"', '$'], $body);
        // 行形如：["kimi-k3", "1M tokens", <>…</>, <>…</>, <>…</>, "1,048,576 tokens"],
        if (! preg_match_all('/^\s*\["([^"]+)",\s*"1M tokens",(.*)\]\s*,?\s*$/m', $body, $rows, PREG_SET_ORDER)) {
            return [];
        }

        $entries = [];
        foreach ($rows as $row) {
            $model = $this->modelName($row[1]);
            if ($model === null || ! str_starts_with($model, 'kimi-')) {
                continue;
            }
            $prices = $this->jsxPrices($row[2]);
            if (count($prices) < 3) {
                continue; // 三率不齐的行不产出（避免残缺数据入流水）
            }
            $entries[] = [
                'model' => $model,
                'match_type' => 'exact',
                'cost_mode' => 'per_token_parts',
                'unit_cost' => null,
                // 列序：命中价在前、未命中价在后（与 DeepSeek 表列序相反）
                'input_rate' => $this->usdPerK($prices[1]),
                'cached_rate' => $this->usdPerK($prices[0]),
                'output_rate' => $this->usdPerK($prices[2]),
                'time_discounts' => null,
            ];
        }

        return $entries;
    }

    public function parseCatalog(string $body): array
    {
        // 目录口径=DocTable rows 在售模型（batch/tools 为独立页不在本 body）；
        // 行提取与 parsePricing 同正则，仅取首格模型 id，不依赖价格列完整性
        $body = str_replace(['\\"', '\\$'], ['"', '$'], $body);
        if (! preg_match_all('/^\s*\["([^"]+)",\s*"1M tokens",(.*)\]\s*,?\s*$/m', $body, $rows, PREG_SET_ORDER)) {
            return [];
        }

        $models = [];
        foreach ($rows as $row) {
            $model = $this->modelName($row[1]);
            if ($model === null || ! str_starts_with($model, 'kimi-')) {
                continue;
            }
            $models[$model] = true;
        }

        return array_keys($models);
    }

    /**
     * 从 DocTable 行的价格段提取前三个价格数值（缓存命中/未命中/输出）。
     * 兼容 JSX（<>{"$"}0.30</>）与纯字符串（"$0.30"）两种元素形态（正文已归一化转义）。
     *
     * @return list<string>
     */
    protected function jsxPrices(string $segment): array
    {
        if (! preg_match_all('/<>\{"\$"\}([0-9.]+)<\/>|\$([0-9.]+)"/', $segment, $matches, PREG_SET_ORDER)) {
            return [];
        }
        $prices = [];
        foreach (array_slice($matches, 0, 3) as $match) {
            $prices[] = ($match[1] ?? '') !== '' ? $match[1] : $match[2];
        }

        return $prices;
    }

    /**
     * $/1M 单元格 → $/1k（÷1000）；无效 → null。
     */
    protected function usdPerK(?string $raw): ?float
    {
        $value = $this->number($raw);

        return $value === null ? null : $value / 1000;
    }
}
