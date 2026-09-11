<?php

declare(strict_types=1);

namespace App\Services\CodingPlanParsers;

/**
 * DeepSeek 官方定价页解析器（SSR HTML 表格）【已验证可抓，2026-09-12】
 *
 * 源：https://api-docs.deepseek.com/zh-cn/quick_start/pricing/
 * 官方口径：元 / 百万 tokens（÷1000 折算为 元 / 1k tokens）；
 * 高峰 = 北京时间周一至五 9:00–12:00、14:00–18:00，其余空闲价 = 高峰一半。
 *
 * 基础价取高峰价（原价），空闲减半编码进 time_discounts —— 窗口数组与迁移
 * 000009 的 DEEPSEEK_WINDOWS 完全一致，保证与库内预置 diff 零漂移。
 * 兼容两种表头布局：「每列单时段」（空闲列 + 高峰列分列）与
 * 「二级表头合并列」（数据行按 空闲/高峰 成对出现）。
 */
class DeepSeekParser extends AbstractCodingPlanParser
{
    /** DeepSeek 官方空闲减半窗口（编码与迁移 000009 一致，勿改） */
    public const OFF_PEAK_WINDOWS = [
        ['name' => '工作日空闲(00-09点)', 'days' => [1, 2, 3, 4, 5], 'start' => '00:00', 'end' => '09:00', 'discount' => 0.5],
        ['name' => '工作日空闲(12-14点)', 'days' => [1, 2, 3, 4, 5], 'start' => '12:00', 'end' => '14:00', 'discount' => 0.5],
        ['name' => '工作日空闲(18-24点)', 'days' => [1, 2, 3, 4, 5], 'start' => '18:00', 'end' => '24:00', 'discount' => 0.5],
        ['name' => '周末全天', 'days' => [6, 7], 'start' => '00:00', 'end' => '24:00', 'discount' => 0.5],
    ];

    /** 官方标价单位 = 每百万 tokens → 库内每 1k tokens 除数 */
    protected const PER_MILLION_DIVISOR = 1000.0;

    public function parsePricing(string $body): array
    {
        $rows = $this->tableRows($body);
        if ($rows === []) {
            return [];
        }

        // 官方页面为「转置表」（模型为列、指标为行、rowspan 分段、单位写在格内）；
        // 部分镜像/渲染变体为「纵向表」（模型为行）。两种布局先转置后纵向，
        // 统一产出 model => metric => phase => value 的费率表再归一化。
        $rates = $this->extractRatesTransposed($rows);
        if ($rates === []) {
            $rates = $this->extractRatesByColumns($rows);
        }

        return $this->ratesToEntries($rates);
    }

    public function parseCatalog(string $body): array
    {
        $rows = $this->tableRows($body);
        $models = [];

        // 转置布局：模型在表头行（首格 = 「模型」）
        foreach ($rows as $cells) {
            if (mb_strtolower(trim($cells[0] ?? '')) !== '模型') {
                continue;
            }
            foreach ($cells as $index => $text) {
                if ($index === 0) {
                    continue;
                }
                $model = $this->modelName($text);
                if ($model !== null) {
                    $models[$model] = true;
                }
            }
            break;
        }

        // 纵向布局：模型在首列（说明性英文短语因含空格不会通过 modelName 校验）
        foreach ($rows as $cells) {
            $model = $this->modelName($cells[0] ?? '');
            if ($model !== null) {
                $models[$model] = true;
            }
        }

        return array_keys($models);
    }

    /**
     * 纵向布局（模型为行）：表头行做指标映射，逐行提取费率。
     *
     * @param  list<list<string>>  $rows
     * @return array<string, array<string, array<string, float>>>  model => metric => phase => value
     */
    protected function extractRatesByColumns(array $rows): array
    {
        // 定位表头行（含「模型」列）→ 列映射；合并布局下数据列数 = 指标列数 × 2
        $mapping = null;
        $pairMode = false;
        $headerIndex = null;
        foreach ($rows as $index => $cells) {
            $joined = mb_strtolower(implode(' ', $cells));
            if (mb_strpos($joined, '模型') === false && mb_strpos($joined, 'model') === false) {
                continue;
            }
            $candidate = $this->mapHeader($cells);
            if ($candidate !== null) {
                $mapping = $candidate;
                $headerIndex = $index;
                $pairMode = isset($rows[$index + 1]) && (count($rows[$index + 1]) - 1) === 2 * count($candidate);
                break;
            }
        }
        if ($mapping === null) {
            return [];
        }

        $rates = [];
        foreach ($rows as $index => $cells) {
            if ($index === $headerIndex) {
                continue;
            }
            $model = $this->modelName($cells[0] ?? '');
            if ($model === null || count($cells) < 2) {
                continue;
            }
            foreach ($mapping as $cellIndex => $spec) {
                $metric = $spec['metric'];
                if ($pairMode) {
                    // 合并列布局：映射列与右侧邻列构成 {metric: idle, peak} 对
                    $parts = preg_split('/\s*\/\s*/u', ($cells[$cellIndex] ?? '').' '.($cells[$cellIndex + 1] ?? '')) ?: [];
                    $idle = $this->number($parts[0] ?? null);
                    $peak = $this->number($parts[1] ?? null);
                    if ($idle !== null) {
                        $rates[$model][$metric]['idle'] = $idle;
                    }
                    if ($peak !== null) {
                        $rates[$model][$metric]['peak'] = $peak;
                    }
                } elseif (($spec['phase'] ?? '') === 'both') {
                    $parts = preg_split('/\s*\/\s*/u', $cells[$cellIndex] ?? '') ?: [];
                    $idle = $this->number($parts[0] ?? null);
                    $peak = $this->number($parts[1] ?? null);
                    if ($idle !== null) {
                        $rates[$model][$metric]['idle'] = $idle;
                    }
                    if ($peak !== null) {
                        $rates[$model][$metric]['peak'] = $peak;
                    }
                } else {
                    $value = $this->number($cells[$cellIndex] ?? null);
                    if ($value !== null) {
                        $rates[$model][$metric][$spec['phase'] ?? 'peak'] = $value;
                    }
                }
            }
        }

        return $rates;
    }

    /**
     * 转置布局（模型为列，官方页面实际结构）：
     *   [模型, deepseek-flash(1), deepseek-v4-pro(2)]          ← 表头行
     *   [价格(3), 百万tokens输入（缓存命中）, 空闲时段, 0.02元, 0.15元]
     *   [高峰时段, 0.04元, 0.30元]                              ← rowspan 延续（指标列缺席）
     * 指标（缓存命中/未命中/输出）跨行 rowspan 延续，空闲/高峰分行；
     * 单位写在数值格内（"0.02元"）。
     *
     * @param  list<list<string>>  $rows
     * @return array<string, array<string, array<string, float>>>  model => metric => phase => value
     */
    protected function extractRatesTransposed(array $rows): array
    {
        $columns = $this->transposedModelColumns($rows);
        if ($columns === []) {
            return [];
        }

        $rates = [];
        $metric = null;
        foreach ($rows as $cells) {
            // 定位本行时段格（空闲时段 / 高峰时段）
            $phase = null;
            $phaseIndex = null;
            foreach ($cells as $index => $text) {
                if (mb_strpos($text, '空闲时段') !== false) {
                    $phase = 'idle';
                    $phaseIndex = $index;
                    break;
                }
                if (mb_strpos($text, '高峰时段') !== false) {
                    $phase = 'peak';
                    $phaseIndex = $index;
                    break;
                }
            }
            if ($phase === null) {
                continue;
            }

            // 指标格可位于时段格之前的任意位置（如 [价格(3), 百万tokens输入（缓存命中）, 空闲时段]）；
            // rowspan 延续行（首格=高峰时段）沿用上一行指标
            for ($i = 0; $i < $phaseIndex; $i++) {
                $rowMetric = $this->metricOf($cells[$i] ?? '');
                if ($rowMetric !== null) {
                    $metric = $rowMetric;
                }
            }
            if ($metric === null) {
                continue;
            }

            // 时段格之后的数值依序对应模型列（rowspan 下所有模型列均有值）
            $values = [];
            for ($i = $phaseIndex + 1, $n = count($cells); $i < $n; $i++) {
                $values[] = $this->number($cells[$i]);
            }
            foreach (array_keys($columns) as $position => $cellIndex) {
                $value = $values[$position] ?? null;
                if ($value !== null) {
                    $rates[$columns[$cellIndex]][$metric][$phase] = $value;
                }
            }
        }

        return $rates;
    }

    /**
     * 转置表模型列映射：首格=「模型」的表头行 → [cellIndex => model]。
     * 模型名带脚注（"deepseek-flash(1)"），modelName() 自动去括号。
     *
     * @param  list<list<string>>  $rows
     * @return array<int, string>
     */
    protected function transposedModelColumns(array $rows): array
    {
        foreach ($rows as $cells) {
            if (mb_strtolower(trim($cells[0] ?? '')) !== '模型') {
                continue;
            }
            $columns = [];
            foreach ($cells as $index => $text) {
                if ($index === 0) {
                    continue;
                }
                $model = $this->modelName($text);
                if ($model !== null) {
                    $columns[$index] = $model;
                }
            }
            if ($columns !== []) {
                return $columns;
            }
        }

        return [];
    }

    /**
     * 指标名归类：缓存命中 → cached；缓存未命中/输入 → input；输出 → output；其余 null。
     */
    protected function metricOf(string $text): ?string
    {
        $text = trim($text);
        if (mb_strpos($text, '缓存命中') !== false || mb_strpos($text, 'cache hit') !== false) {
            return 'cached';
        }
        if (mb_strpos($text, '缓存未命中') !== false || mb_strpos($text, 'cache miss') !== false
            || mb_strpos($text, '输入') !== false || mb_strpos($text, 'input') !== false) {
            return 'input';
        }
        if (mb_strpos($text, '输出') !== false || mb_strpos($text, 'output') !== false) {
            return 'output';
        }

        return null;
    }

    /**
     * 费率表 → 标准化条目（元/百万 tokens → 元/1k tokens）。
     * 基础价取高峰（原价）；无缓存价 = 输入价（保守不折扣）；出现空闲价 → 附空闲减半窗口。
     *
     * @param  array<string, array<string, array<string, float>>>  $rates
     * @return list<array{model: string, match_type: string, cost_mode: string, unit_cost: null, input_rate: float, cached_rate: float, output_rate: float, time_discounts: array|null}>
     */
    protected function ratesToEntries(array $rates): array
    {
        $entries = [];
        foreach ($rates as $model => $metricRates) {
            $input = $metricRates['input']['peak'] ?? $metricRates['input']['idle'] ?? null;
            $output = $metricRates['output']['peak'] ?? $metricRates['output']['idle'] ?? null;
            if ($input === null || $output === null) {
                continue;
            }
            $cached = $metricRates['cached']['peak'] ?? $metricRates['cached']['idle'] ?? $input;
            $hasIdle = isset($metricRates['input']['idle']) || isset($metricRates['output']['idle']);

            $entries[$model.'|exact'] = [
                'model' => $model,
                'match_type' => 'exact',
                'cost_mode' => 'per_token_parts',
                'unit_cost' => null,
                'input_rate' => round($input / self::PER_MILLION_DIVISOR, 8),
                'cached_rate' => round($cached / self::PER_MILLION_DIVISOR, 8),
                'output_rate' => round($output / self::PER_MILLION_DIVISOR, 8),
                'time_discounts' => $hasIdle ? self::OFF_PEAK_WINDOWS : null,
            ];
        }

        return array_values($entries);
    }

    /**
     * 表头 → [单元格索引 => ['metric' => input|cached|output, 'phase' => peak|idle|both]]。
     * 识别不出 ≥2 个价格列时返回 null（非定价表）。
     *
     * @param  list<string>  $cells
     * @return array<int, array{metric: string, phase: string}>|null
     */
    protected function mapHeader(array $cells): ?array
    {
        $mapping = [];
        foreach ($cells as $index => $text) {
            $text = mb_strtolower(trim($text));
            if ($index === 0 || $text === '') {
                continue;
            }
            $metric = null;
            if (mb_strpos($text, '缓存命中') !== false || mb_strpos($text, 'cache hit') !== false) {
                $metric = 'cached';
            } elseif (mb_strpos($text, '缓存未命中') !== false || mb_strpos($text, 'cache miss') !== false
                || mb_strpos($text, '输入') !== false || mb_strpos($text, 'input') !== false) {
                $metric = 'input';
            } elseif (mb_strpos($text, '输出') !== false || mb_strpos($text, 'output') !== false) {
                $metric = 'output';
            }
            if ($metric === null) {
                continue;
            }
            $hasIdle = mb_strpos($text, '空闲') !== false || mb_strpos($text, 'off-peak') !== false || mb_strpos($text, 'idle') !== false;
            $hasPeak = mb_strpos($text, '高峰') !== false || mb_strpos($text, 'peak') !== false;
            $phase = $hasIdle && $hasPeak ? 'both' : ($hasIdle ? 'idle' : 'peak');
            $mapping[$index] = ['metric' => $metric, 'phase' => $phase];
        }

        return count($mapping) >= 2 ? $mapping : null;
    }
}
