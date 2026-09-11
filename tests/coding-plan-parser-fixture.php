<?php

declare(strict_types=1);

/**
 * P1-2 解析器 fixture 自测（非 PHPUnit，独立脚本，跑完即退出）
 * 数据取自 docs/upstream-snapshots/2026-09-12/pricing-data.md 的官方原始数值。
 */
require '/Users/snails/Project/PeaseAPI/vendor/autoload.php';

use App\Services\CodingPlanParsers\DeepSeekParser;
use App\Services\CodingPlanParsers\OpenAiMarkdownParser;

$fail = 0;
function check(string $name, bool $cond): void
{
    global $fail;
    echo ($cond ? '  ✓ ' : '  ✗ ').$name."\n";
    if (! $cond) {
        $fail++;
    }
}

// ---- DeepSeek（HTML）----
// 真实页面 = 转置表（模型为列、rowspan 分段、单位在格内），结构取自 2026-09-12 原始快照
$htmlTransposed = <<<'HTML'
<table>
<tr><th>模型</th><th>deepseek-flash(1)</th><th>deepseek-v4-pro(2)</th></tr>
<tr><td>模型版本</td><td>DeepSeek-V4.1-Flash</td><td>DeepSeek-V4-Pro-0813</td></tr>
<tr><td>上下文长度</td><td>1M</td><td>1M</td></tr>
<tr><td>输出长度</td><td>最大 384K</td><td>最大 384K</td></tr>
<tr><td>价格(3)</td><td>百万tokens输入（缓存命中）</td><td>空闲时段</td><td>0.02元</td><td>0.15元</td></tr>
<tr><td>高峰时段</td><td>0.04元</td><td>0.30元</td></tr>
<tr><td>百万tokens输入（缓存未命中）</td><td>空闲时段</td><td>1元</td><td>4.5元</td></tr>
<tr><td>高峰时段</td><td>2元</td><td>9.0元</td></tr>
<tr><td>百万tokens输出</td><td>空闲时段</td><td>4元</td><td>13.5元</td></tr>
<tr><td>高峰时段</td><td>8元</td><td>27.0元</td></tr>
<tr><td>并发限制(4)</td><td>2500</td><td>500</td></tr>
</table>
HTML;

// 纵向表（模型为行）为兜底布局（镜像/渲染变体）
$htmlColumn = <<<'HTML'
<table><thead><tr><th>模型</th><th>缓存命中 空闲</th><th>缓存命中 高峰</th><th>缓存未命中 空闲</th><th>缓存未命中 高峰</th><th>输出 空闲</th><th>输出 高峰</th></tr></thead>
<tbody>
<tr><td>deepseek-flash（DeepSeek-V4.1-Flash）</td><td>0.02</td><td>0.04</td><td>1.0</td><td>2.0</td><td>4.0</td><td>8.0</td></tr>
<tr><td>deepseek-v4-pro</td><td>0.15</td><td>0.30</td><td>4.5</td><td>9.0</td><td>13.5</td><td>27.0</td></tr>
</tbody></table>
HTML;

$p = new DeepSeekParser();
$entriesT = $p->parsePricing($htmlTransposed);
$entriesC = $p->parsePricing($htmlColumn);
check('deepseek 转置布局解析出 2 条', count($entriesT) === 2);
check('deepseek 纵向布局解析出 2 条（兜底）', count($entriesC) === 2);
check('两种布局产出一致', json_encode($entriesT) === json_encode($entriesC));
$flash = $entriesT[0] ?? [];
check('flash model=deepseek-flash', ($flash['model'] ?? '') === 'deepseek-flash');
check('flash input_rate=0.002（2.0元/1M）', abs(($flash['input_rate'] ?? 0) - 0.002) < 1e-9);
check('flash cached_rate=0.00004（0.04元/1M）', abs(($flash['cached_rate'] ?? 0) - 0.00004) < 1e-12);
check('flash output_rate=0.008（8元/1M）', abs(($flash['output_rate'] ?? 0) - 0.008) < 1e-9);
check('flash time_discounts=4 窗口', count($flash['time_discounts'] ?? []) === 4);
$expected = [
    ['name' => '工作日空闲(00-09点)', 'days' => [1, 2, 3, 4, 5], 'start' => '00:00', 'end' => '09:00', 'discount' => 0.5],
    ['name' => '工作日空闲(12-14点)', 'days' => [1, 2, 3, 4, 5], 'start' => '12:00', 'end' => '14:00', 'discount' => 0.5],
    ['name' => '工作日空闲(18-24点)', 'days' => [1, 2, 3, 4, 5], 'start' => '18:00', 'end' => '24:00', 'discount' => 0.5],
    ['name' => '周末全天', 'days' => [6, 7], 'start' => '00:00', 'end' => '24:00', 'discount' => 0.5],
];
check('窗口编码与迁移 000009 一致', json_encode($flash['time_discounts'] ?? null) === json_encode($expected));
$pro = $entriesT[1] ?? [];
check('pro output_rate=0.027（27元/1M）', abs(($pro['output_rate'] ?? 0) - 0.027) < 1e-9);
check('deepseek catalog=2 模型（转置）', $p->parseCatalog($htmlTransposed) === ['deepseek-flash', 'deepseek-v4-pro']);

// ---- OpenAI（Markdown，短上下文价 + 多模型共享行 + Batch 首表优先）----
$md = <<<'MD'
## Standard pricing

| Model | Input | Cached input | Output |
|---|---|---|---|
| gpt-6-astra | 10.00（20.00） | 1.00（2.00） | 50.00（75.00） |
| gpt-5.6-sol | 4.00（8.00） | 0.40（0.80） | 20.00（30.00） |
| gpt-5.5 | 5.00（10.00） | - | 30.00（45.00） |
| gpt-5.4-mini / nano | 0.75 / 0.20 | 0.075 / 0.02 | 4.50 / 1.25 |
| gpt-5.1 / gpt-5 | 1.25 | 0.125 | 10.00 |
| gpt-4.1 / mini / nano | 2.00 / 0.40 / 0.10 | 0.50 / 0.10 / 0.025 | 8.00 / 1.60 / 0.40 |

## Batch API pricing

| Model | Input | Cached input | Output |
|---|---|---|---|
| gpt-5.6-sol | 2.00 | 0.20 | 10.00 |
MD;

$o = new OpenAiMarkdownParser();
$byModel = [];
foreach ($o->parsePricing($md) as $entry) {
    $byModel[$entry['model']] = $entry;
}
check('openai 解析条目 = 10（Batch 表同名不覆盖）', count($byModel) === 10);
check('astra input=0.01（10 USD/1M）', abs(($byModel['gpt-6-astra']['input_rate'] ?? 0) - 0.01) < 1e-9);
check('astra output=0.05（长上下文价被忽略）', abs(($byModel['gpt-6-astra']['output_rate'] ?? 0) - 0.05) < 1e-9);
check('sol 首表优先（标准价 0.004 非 Batch 0.002）', abs(($byModel['gpt-5.6-sol']['input_rate'] ?? 0) - 0.004) < 1e-9);
check('5.5 无缓存价 → cached=input（保守）', abs(($byModel['gpt-5.5']['cached_rate'] ?? 0) - 0.005) < 1e-9);
check('裸缩写补全 gpt-5.4-mini/nano', isset($byModel['gpt-5.4-mini'], $byModel['gpt-5.4-nano']));
check('5.4-nano input=0.0002', abs(($byModel['gpt-5.4-nano']['input_rate'] ?? 0) - 0.0002) < 1e-9);
check('5.1/5 同价共用 0.00125', isset($byModel['gpt-5.1'], $byModel['gpt-5']) && abs(($byModel['gpt-5']['input_rate'] ?? 0) - 0.00125) < 1e-9);
check('4.1-nano cached=0.000025', abs(($byModel['gpt-4.1-nano']['cached_rate'] ?? 0) - 0.000025) < 1e-9);
check('openai catalog 反引号提取', in_array('gpt-5.6-sol', $o->parseCatalog('见 `gpt-5.6-sol` 与 `o4-mini` 说明'), true));

echo $fail === 0 ? "\n✅ 解析器 fixture 全部通过\n" : "\n❌ {$fail} 项失败\n";
exit($fail === 0 ? 0 : 1);
