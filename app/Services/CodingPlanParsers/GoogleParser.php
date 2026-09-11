<?php

declare(strict_types=1);

namespace App\Services\CodingPlanParsers;

/**
 * Google Gemini API 定价解析器（https://ai.google.dev/gemini-api/docs/pricing，SSR 中文机器翻译版）。
 *
 * 页面结构（2026-09-12 快照核实）：
 *  - 文档顺序 = 模型锚点 <h2/h3 id="gemini-..."> → 服务层级子标题（standard/batch/flex/priority，
 *    重复时带 _N）→ 每层级一张价格表；模型名只在锚点里，表格本身不含。
 *  - 每表行 = [指标 | 免费层 | 付费层]；付费层价格是双语双价文本：
 *    「2026 年 12 月 31 日之前为 0.75 美元。 自 2027 年 1 月 1 日起为 1.50 美元。」
 *  - 缓存行还混有「/100 万个 token/小时（存储价格）」句子，必须按句剔除。
 *
 * 口径决策：
 *  - 只取 standard（标准）层 —— batch=批量半价、flex/priority 是 SLA 档，与 OpenAI Batch 列同理跳过；
 *  - 基础价取「恢复价」（2027-01-01 起长期价）——促销价归 P2 promotions 表达，
 *    避免 2027-01-01 全表 changed 刷屏；
 *  - 单位 ÷1000（$/1M → $/1k），cost_mode=per_token_parts（输入/缓存/输出三率）。
 */
class GoogleParser extends AbstractCodingPlanParser
{
    public function parsePricing(string $body): array
    {
        // 按文档顺序交错捕获标题与表格
        if (! preg_match_all(
            '/<h[23][^>]*\bid="([^"]+)"[^>]*>(?P<htext>.*?)<\/h[23]>|<table[^>]*>(?P<table>.*?)<\/table>/is',
            $body,
            $hits,
            PREG_SET_ORDER
        )) {
            return [];
        }

        $entries = [];
        $model = null; // 当前模型锚点 id（gemini-*）
        $wantTable = false; // 下一张表是否 standard 层
        foreach ($hits as $hit) {
            $id = $hit[1] ?? '';
            if ($id !== '') {
                if (preg_match('/^gemini-[a-z0-9.-]+$/i', $id)) {
                    $model = mb_strtolower($id);
                    $wantTable = false; // 新模型默认不带表，等 standard 子标题
                } elseif (preg_match('/^standard(_\d+)?$/i', $id)) {
                    $wantTable = $model !== null;
                } else {
                    $wantTable = false; // batch/flex/priority/free/paid 等
                }

                continue;
            }

            if ($model === null || ! $wantTable) {
                continue;
            }
            $wantTable = false; // 一表一消费，后续同层级表（重复 standard）不再取
            if (($entry = $this->parseStandardTable((string) $hit['table'], $model)) !== null) {
                $entries[] = $entry;
            }
        }

        return $entries;
    }

    public function parseCatalog(string $body): array
    {
        return []; // google 无独立目录源
    }

    /**
     * standard 层价格表 → 条目（无输入/输出价的表如 TTS/图像 → null）。
     *
     * @return array{model: string, match_type: string, cost_mode: string, unit_cost: null, input_rate: ?float, cached_rate: ?float, output_rate: ?float, time_discounts: null}|null
     */
    protected function parseStandardTable(string $tableHtml, string $model): ?array
    {
        $input = $cached = $output = null;
        foreach ($this->tableRows($tableHtml) as $cells) {
            $label = $cells[0] ?? '';
            $paid = $cells === [] ? null : end($cells); // 付费层 = 最后一列
            if (preg_match('/输入价格|Input price/iu', $label)) {
                $input = $this->recurringPrice($paid);
            } elseif (preg_match('/输出价格|Output price/iu', $label)) {
                $output = $this->recurringPrice($paid);
            } elseif (preg_match('/上下文缓存|Context caching|Context cache/iu', $label)) {
                $cached = $this->recurringPrice($paid); // 存储价由候选排除逻辑过滤
            }
        }

        if ($input === null && $output === null) {
            return null; // 非按 token 计费的表（接地/TTS/图像）
        }

        return [
            'model' => $model,
            'match_type' => 'exact',
            'cost_mode' => 'per_token_parts',
            'unit_cost' => null,
            'input_rate' => $input,
            'cached_rate' => $cached,
            'output_rate' => $output,
            'time_discounts' => null,
        ];
    }

    /**
     * 付费层单元格 → 长期价（$/1M → ÷1000 返回 $/1k）。
     * 「...之前为 0.75 美元。自 2027 年 1 月 1 日起为 1.50 美元。」→ 1.50（恢复价）；
     * 分档文本「1.25 美元：提示 <= 20 万... 4.50 美元/...小时（存储价格）」→ 首个非噪声价 1.25；
     * 按图/按秒/按小时（存储）等非 token 计价候选被后视排除；「不可用」→ null；「免费」→ 0.0。
     */
    protected function recurringPrice(?string $text): ?float
    {
        if ($text === null || trim($text) === '') {
            return null;
        }
        $text = trim($text);
        if (preg_match('/不可用|暂不提供|not available/iu', $text)) {
            return null;
        }

        // 双价 → 恢复价（中文机器翻译「自 2027 年 1 月 1 日起为 1.5 美元」/「2027 年 1 月 1 日起为 0.075 美元」）
        if (preg_match('/起为\s*\$?([\d.]+)/u', $text, $m)) {
            return (float) $m[1] / 1000;
        }
        // 英文原文兜底：「from January 1, 2027: $1.50」
        if (preg_match('/(?:from|starting|after)[^$]*\$\s*([\d.]+)/iu', $text, $m)) {
            return (float) $m[1] / 1000;
        }

        // 单价候选：分「每张/每秒引导的换算价」（前视噪声组，命中即跳过）与正常价两类；
        // 后视噪声仅认「/」后无空格的计价单位（「/图」「/小时」）——「（文本 / 图片）」这类
        // 带空格的模态列表是放行的
        if (preg_match_all(
            '/(?:相当于|每张|每秒|每小时|每 1,000|每千)[^$]{0,16}\$?([\d.]+)\s*(?:美元|USD)|(?:^|[^\d.])([\d.]+)\s*(?:美元|USD)|\$\s*([\d.]+)/u',
            $text,
            $m,
            PREG_SET_ORDER | PREG_OFFSET_CAPTURE
        )) {
            $count = count($m);
            foreach ($m as $i => $hit) {
                if ((int) $hit[1][1] >= 0) {
                    continue; // 「相当于每张图片 0.0011 美元」类换算价
                }
                $value = $hit[2][0] !== '' ? $hit[2][0] : $hit[3][0];
                $start = (int) $hit[0][1];
                $valueEnd = $start + strlen((string) $hit[0][0]);
                // 后视窗口截止到下一候选起点，避免把后续候选的噪声算到当前候选头上
                $windowEnd = $i + 1 < $count ? (int) $m[$i + 1][0][1] : $valueEnd + 40;
                $after = substr($text, $valueEnd, max(0, $windowEnd - $valueEnd));
                if (preg_match('/\/(?! )(?:图|张|秒|小时|image|second|hour)|存储价格|RPD/u', $after)) {
                    continue; // 图价 / 计次价 / 按小时存储价
                }

                return (float) $value / 1000;
            }
        }

        return preg_match('/免费|free/iu', $text) ? 0.0 : null;
    }
}
