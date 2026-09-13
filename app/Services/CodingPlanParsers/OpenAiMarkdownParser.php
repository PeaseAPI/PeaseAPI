<?php

declare(strict_types=1);

namespace App\Services\CodingPlanParsers;

/**
 * OpenAI 官方定价页解析器（Markdown，.md 后缀直取）【已验证可抓，2026-09-12，需代理】
 *
 * 源：https://platform.openai.com/docs/pricing.md（P0 技巧：文档页支持 .md 后缀）
 * 官方口径：USD / 百万 tokens（÷1000 折算为 USD / 1k tokens）；括号内为长上下文价
 * （"4.00（8.00）" → 取短上下文 4.00，长上下文暂不进比率表）。
 *
 * 容错点：
 *  - 一行多模型共享价（"gpt-5.4-mini / nano" + "0.75 / 0.20"）按 ' / ' 拆分一一配对；
 *    裸缩写（nano/mini/pro 等）自动补全为首个全名的前缀（gpt-5.4-nano）；
 *  - 各模型数值个数与模型数不一致时，整列共用首个数值（如 gpt-5.1 / gpt-5 同价）；
 *  - Batch/Priority/Fast/Long/Flex 列跳过；多个表出现同名模型时首表优先（标准价表在前）；
 *  - 无缓存价列（如 gpt-5.5）→ cached_rate = input_rate（保守不折扣，cache 写价列忽略）。
 */
class OpenAiMarkdownParser extends AbstractCodingPlanParser
{
    protected const PER_MILLION_DIVISOR = 1000.0;

    /** 裸缩写 → 用首个全名的公共前缀补全 */
    protected const BARE_SUFFIXES = ['mini', 'nano', 'pro', 'flash', 'light', 'turbo'];

    public function parsePricing(string $body): array
    {
        $entries = [];
        foreach ($this->markdownTables($body) as $table) {
            $cols = $this->mapColumns($table['header']);
            if ($cols === null) {
                continue;
            }
            foreach ($table['rows'] as $cells) {
                $this->addRow($entries, $cells, $cols);
            }
        }

        return array_values($entries);
    }

    public function parseCatalog(string $body): array
    {
        // 目录页（docs/models.md）：
        //  - 新结构（2026-09-13 官方改版）：列表项链接 /api/docs/models/<id>.md（Featured +
        //    Browse full catalog 两段，链接路径即模型 id，天然排除 pricing/deprecations 等页）；
        //  - 反引号兜底：旧表格结构 + 正文标注 id 特例（如 GPT-Rosalind 的
        //    「Model ID: `gpt-rosalind-research`」，其链接指向 pricing 锚点）。
        // 前缀过滤沿用旧口径，目录与库内行集合口径稳定可比。
        $prefix = '/^(gpt|o\d|chatgpt|omni|dall-e|dall·e|whisper|tts|embed|codex|sora|davinci|babbage)/i';
        $models = [];

        if (preg_match_all('#/api/docs/models/([A-Za-z0-9][A-Za-z0-9._-]{1,96})\.md#', $body, $matches)) {
            foreach ($matches[1] as $id) {
                if (preg_match($prefix, $id)) {
                    $models[mb_strtolower($id)] = true;
                }
            }
        }

        if (preg_match_all('/`([A-Za-z0-9][A-Za-z0-9._-]{2,96})`/', $body, $matches)) {
            foreach ($matches[1] as $id) {
                if (preg_match($prefix, $id)) {
                    $models[mb_strtolower($id)] = true;
                }
            }
        }

        return array_keys($models);
    }

    /**
     * 表头 → ['model' => int, 'input' => int, 'cached' => int|null, 'output' => int]；
     * 缺 model / input / output 列（如 embedding、语音表）返回 null。
     *
     * @param  list<string>  $header
     * @return array{model: int, input: int, cached: ?int, output: int}|null
     */
    protected function mapColumns(array $header): ?array
    {
        $cols = [];
        foreach ($header as $index => $text) {
            $text = mb_strtolower(trim($text));
            $isExcluded = mb_strpos($text, 'batch') !== false
                || mb_strpos($text, 'priority') !== false
                || mb_strpos($text, 'fast') !== false
                || mb_strpos($text, 'long') !== false
                || mb_strpos($text, 'flex') !== false;
            if ($isExcluded) {
                continue;
            }
            if (! isset($cols['model']) && mb_strpos($text, 'model') !== false) {
                $cols['model'] = $index;

                continue;
            }
            if (mb_strpos($text, 'cached') !== false || mb_strpos($text, 'cache') !== false) {
                $cols['cached'] = $cols['cached'] ?? $index; // 取首个 cache 列（读价），写价列忽略

                continue;
            }
            if (! isset($cols['input']) && (mb_strpos($text, 'input') !== false || mb_strpos($text, '输入') !== false)) {
                $cols['input'] = $index;

                continue;
            }
            if (! isset($cols['output']) && (mb_strpos($text, 'output') !== false || mb_strpos($text, '输出') !== false)) {
                $cols['output'] = $index;
            }
        }
        if (! isset($cols['model'], $cols['input'], $cols['output'])) {
            return null;
        }

        return ['model' => $cols['model'], 'input' => $cols['input'], 'cached' => $cols['cached'] ?? null, 'output' => $cols['output']];
    }

    /**
     * 数据行 → 逐模型写入 $entries（引用累积；key = model|match_type，首表优先不覆盖）。
     *
     * @param  array{model: int, input: int, cached: ?int, output: int}  $cols
     * @param  array<string, array{model: string, match_type: string, cost_mode: string, unit_cost: null, input_rate: float, cached_rate: float, output_rate: float, time_discounts: null}>  $entries
     */
    protected function addRow(array &$entries, array $cells, array $cols): void
    {
        $models = $this->splitModelNames($cells[$cols['model']] ?? '');
        if ($models === []) {
            return;
        }

        $raw = [
            'input_rate' => $cells[$cols['input']] ?? '',
            'cached_rate' => $cols['cached'] !== null ? ($cells[$cols['cached']] ?? '') : '-',
            'output_rate' => $cells[$cols['output']] ?? '',
        ];

        // 各列按 ' / ' 拆分；与模型数对不齐时整列共用首个数值
        $perModel = [];
        foreach ($raw as $field => $text) {
            $parts = $this->splitValues($text);
            $perModel[$field] = count($parts) === count($models)
                ? $parts
                : array_fill(0, count($models), $parts[0] ?? null);
        }

        foreach ($models as $index => $model) {
            $entry = $this->buildEntry($model, [
                'input_rate' => $perModel['input_rate'][$index] ?? null,
                'cached_rate' => $perModel['cached_rate'][$index] ?? null,
                'output_rate' => $perModel['output_rate'][$index] ?? null,
            ]);
            if ($entry !== null && ! isset($entries[$entry['model'].'|'.$entry['match_type']])) {
                $entries[$entry['model'].'|'.$entry['match_type']] = $entry;
            }
        }
    }

    /**
     * 模型格 → 模型名数组（' / ' 分隔；裸缩写补全为首个全名前缀）。
     *
     * @return list<string>
     */
    protected function splitModelNames(string $raw): array
    {
        $parts = array_map('trim', explode('/', $this->shortContext($raw) ?? ''));
        $parts = array_values(array_filter($parts, fn (string $part): bool => $part !== ''));
        if ($parts === []) {
            return [];
        }

        $models = [];
        $prefix = null;
        foreach ($parts as $part) {
            $bare = mb_strtolower(trim($part));
            // 裸缩写优先判定（'nano' 本身能通过模型名校验，必须先于全名匹配）
            if (in_array($bare, self::BARE_SUFFIXES, true)) {
                if ($prefix !== null && $prefix !== '') {
                    $models[] = $prefix.$bare; // "gpt-5.4-mini / nano" → gpt-5.4-nano
                }

                continue;
            }
            $normalized = $this->modelName($part);
            if ($normalized !== null) {
                $models[] = $normalized;
                if ($prefix === null) {
                    // 公共前缀 = 首个全名去掉尾部裸缩写段（gpt-5.4-mini → gpt-5.4-；gpt-4.1 → gpt-4.1-）
                    $base = $normalized;
                    foreach (self::BARE_SUFFIXES as $suffix) {
                        if (str_ends_with($base, '-'.$suffix)) {
                            $base = substr($base, 0, -(strlen($suffix) + 1));

                            break;
                        }
                    }
                    $prefix = $base.'-';
                }
            }
        }

        return $models;
    }

    /**
     * 数值格 → 每模型数值列表（' / ' 拆分；无分隔 = 单值；'-' = null）。
     *
     * @return list<float|null>
     */
    protected function splitValues(string $raw): array
    {
        $short = $this->shortContext($raw);
        if ($short !== null && mb_strpos($short, '/') !== false) {
            return array_map(fn (string $part): ?float => $this->number($part), array_map('trim', explode('/', $short)));
        }

        return [$this->number($short)];
    }

    /**
     * 单模型三段值 → 标准化条目（USD/1M → USD/1k；cached 缺省 = input，保守不折扣）。
     *
     * @param  array{input_rate: float|null, cached_rate: float|null, output_rate: float|null}  $rates
     * @return array{model: string, match_type: string, cost_mode: string, unit_cost: null, input_rate: float, cached_rate: float, output_rate: float, time_discounts: null}|null
     */
    protected function buildEntry(string $model, array $rates): ?array
    {
        $input = $rates['input_rate'] ?? null;
        $output = $rates['output_rate'] ?? null;
        if ($input === null || $output === null) {
            return null;
        }

        return [
            'model' => $model,
            'match_type' => 'exact',
            'cost_mode' => 'per_token_parts',
            'unit_cost' => null,
            'input_rate' => round($input / self::PER_MILLION_DIVISOR, 8),
            'cached_rate' => round(($rates['cached_rate'] ?? $input) / self::PER_MILLION_DIVISOR, 8),
            'output_rate' => round($output / self::PER_MILLION_DIVISOR, 8),
            'time_discounts' => null,
        ];
    }
}
