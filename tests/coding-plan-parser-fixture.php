<?php

declare(strict_types=1);

/**
 * P1-2 解析器 fixture 自测（非 PHPUnit，独立脚本，跑完即退出）
 * 数据取自 docs/upstream-snapshots/2026-09-12/pricing-data.md 的官方原始数值。
 */
require '/Users/snails/Project/PeaseAPI/vendor/autoload.php';

use App\Services\CodingPlanParsers\AnthropicParser;
use App\Services\CodingPlanParsers\DeepSeekParser;
use App\Services\CodingPlanParsers\GoogleParser;
use App\Services\CodingPlanParsers\OpenAiMarkdownParser;
use App\Services\CodingPlanParsers\ZhipuMarkdownParser;

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

$p = new DeepSeekParser;
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

$o = new OpenAiMarkdownParser;
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

// ---- Google（HTML：锚点+层级子标题+表；双价取恢复价；standard 层 only）----
$gHtml = <<<'HTML'
<h2 id="gemini-3.5-flash">Gemini 3.5 Flash</h2>
<h3 id="standard">标准版</h3>
<table>
<tr><td></td><td>免费层级</td><td>付费层级（美元/100 万个 token）</td></tr>
<tr><td>输入价格</td><td>免费</td><td>2026 年 12 月 31 日之前为 0.75 美元。 自 2027 年 1 月 1 日起为 1.50 美元。</td></tr>
<tr><td>上下文缓存价格</td><td>不可用</td><td>2027 年 1 月 1 日起为 0.15 美元。 2026 年 12 月 31 日之前为 0.50 美元/100 万个 token/小时（存储价格）。</td></tr>
<tr><td>输出价格（包括思考 token）</td><td>免费</td><td>3.75 美元</td></tr>
</table>
<h3 id="batch">批量</h3>
<table><tr><td>输入价格</td><td>0.375 美元</td></tr></table>
<h2 id="gemini-2.5-pro">Gemini 2.5 Pro</h2>
<h3 id="standard">标准版</h3>
<table>
<tr><td>输入价格</td><td>免费</td><td>1.25 美元：提示 &lt;= 20 万个 token 2.50 美元：提示 &gt; 20 万个 token</td></tr>
<tr><td>上下文缓存价格</td><td>不可用</td><td>0.125 美元：提示 &lt;= 20 万个 token 0.25 美元：提示 &gt; 20 万个 token 4.50 美元/100 万个 token/小时（存储价格）</td></tr>
<tr><td>输出价格</td><td>免费</td><td>10.00 美元：提示 &lt;= 20 万个 token 15.00 美元：提示 &gt; 20 万个 token</td></tr>
</table>
<h2 id="gemini-3-pro-image">Gemini 3 Pro Image</h2>
<h3 id="standard">标准版</h3>
<table>
<tr><td>输入价格</td><td>不可用</td><td>2.00 美元（文本/图片）， 相当于每张图片 0.0011 美元*</td></tr>
<tr><td>输出价格</td><td>不可用</td><td>12.00 美元（文本和思考） 120.00 美元（图片） 相当于每张 1K/2K 图片 0.134 美元**</td></tr>
</table>
HTML;

$g = new GoogleParser;
$gEntries = [];
foreach ($g->parsePricing($gHtml) as $entry) {
    $gEntries[$entry['model']] = $entry;
}
check('google 解析 3 条（standard 层；batch 表跳过）', count($gEntries) === 3);
check('3.5-flash input=0.0015（恢复价 1.50 非促销 0.75）', abs(($gEntries['gemini-3.5-flash']['input_rate'] ?? 0) - 0.0015) < 1e-9);
check('3.5-flash cached=0.00015（存储句不污染）', abs(($gEntries['gemini-3.5-flash']['cached_rate'] ?? 0) - 0.00015) < 1e-9);
check('3.5-flash output=0.00375', abs(($gEntries['gemini-3.5-flash']['output_rate'] ?? 0) - 0.00375) < 1e-9);
check('2.5-pro input=0.00125（分档取首档 token 价）', abs(($gEntries['gemini-2.5-pro']['input_rate'] ?? 0) - 0.00125) < 1e-9);
check('2.5-pro cached=0.000125（末尾存储价被后视排除）', abs(($gEntries['gemini-2.5-pro']['cached_rate'] ?? 0) - 0.000125) < 1e-9);
check('2.5-pro output=0.01', abs(($gEntries['gemini-2.5-pro']['output_rate'] ?? 0) - 0.01) < 1e-9);
check('3-pro-image input=null（模态列表+每张换算价均不计）', isset($gEntries['gemini-3-pro-image']) && $gEntries['gemini-3-pro-image']['input_rate'] === null);
check('3-pro-image output=0.012（图片换算价不计）', abs(($gEntries['gemini-3-pro-image']['output_rate'] ?? 0) - 0.012) < 1e-9);
check('google 无独立目录源（catalog=空数组）', $g->parseCatalog($gHtml) === []);

// ---- Anthropic（div 表格平铺 <tr>：显示名转 id；Batch 表去重；CCU 行跳过）----
$aHtml = <<<'HTML'
<tr><td>Model</td><td>Base input tokens</td><td>5m cache writes</td><td>1h cache writes</td><td>Cache hits and refreshes</td><td>Output tokens</td></tr>
<tr><td>Claude Opus 4.6</td><td>$5 / MTok</td><td>$6.25 / MTok</td><td>$10 / MTok</td><td>$0.50 / MTok1</td><td>$25 / MTok</td></tr>
<tr><td>Claude Sonnet 4.5</td><td>$3 / MTok</td><td>$3.75 / MTok</td><td>$6 / MTok</td><td>$0.30 / MTok</td><td>$15 / MTok</td></tr>
<tr><td>Claude Haiku 3.5 (retired, except on Bedrock and Google Cloud)</td><td>$0.80 / MTok</td><td>$1 / MTok</td><td>$1.60 / MTok</td><td>$0.08 / MTok</td><td>$4 / MTok</td></tr>
<tr><td>Concept</td><td>Details</td></tr>
<tr><td>Billing unit</td><td>Claude Consumption Unit (CCU)</td></tr>
<tr><td>Model</td><td>Batch input</td><td>Batch output</td></tr>
<tr><td>Claude Opus 4.6</td><td>$2.50 / MTok</td><td>$12.50 / MTok</td></tr>
HTML;

$a = new AnthropicParser;
$aEntries = $a->parsePricing($aHtml);
$aByModel = [];
foreach ($aEntries as $entry) {
    $aByModel[$entry['model']] = $entry;
}
check('anthropic 解析 3 条（Batch 表同名被首条去重；CCU 行跳过）', count($aByModel) === 3);
check('opus-4.6 input=0.005（$5/MTok → $/1k）', abs(($aByModel['claude-opus-4.6']['input_rate'] ?? 0) - 0.005) < 1e-9);
check('opus-4.6 cached=0.0005（脚注数字 $0.50/MTok1 截断正确）', abs(($aByModel['claude-opus-4.6']['cached_rate'] ?? 0) - 0.0005) < 1e-9);
check('opus-4.6 output=0.025（Batch 行半价 0.0125 未覆盖首条）', abs(($aByModel['claude-opus-4.6']['output_rate'] ?? 0) - 0.025) < 1e-9);
check('显示名转 id + 括号注释剥离（retired 行照解析）', isset($aByModel['claude-haiku-3.5']) && abs(($aByModel['claude-haiku-3.5']['input_rate'] ?? 0) - 0.0008) < 1e-9);
check('5m/1h cache writes 列不进任何字段', ! isset($aEntries[0]['cache_write_5m']) && count($aEntries[0]) === 8);

// ---- Zhipu（Mintlify .md：套餐积分配额页 → 仅 catalog；模型名含 U+2011 非断连字符）----
$zMd = <<<'MD'
| 套餐类型 | 5 小时积分 | 每周积分 |
| :-----: | :----: | :-----: |
| Lite 套餐 | 2,000 | 10,000 |
| Pro 套餐 | 12,000 | 60,000 |

| 缓存命中率 | 模型 | Lite（亿 Tokens/周） | Pro（亿 Tokens/周） |
| :---: | :-----------: | :--------------: | :--------------: |
| 95% | GLM‑5.3 | 0.48～0.97 | 2.90～5.80 |
| 95% | GLM‑5.3‑Flash | 1.46～2.92 | 8.77～17.55 |
| 96% | GLM‑5.2 | 0.50～0.99 | 2.97～5.95 |
MD;

$z = new ZhipuMarkdownParser;
check('zhipu parsePricing=空（套餐积分页无模型按量价）', $z->parsePricing($zMd) === []);
check('zhipu catalog=3（U+2011 归一化为普通连字符）', $z->parseCatalog($zMd) === ['glm-5.3', 'glm-5.3-flash', 'glm-5.2']);

echo $fail === 0 ? "\n✅ 解析器 fixture 全部通过\n" : "\n❌ {$fail} 项失败\n";
exit($fail === 0 ? 0 : 1);

echo $fail === 0 ? "\n✅ 解析器 fixture 全部通过\n" : "\n❌ {$fail} 项失败\n";
exit($fail === 0 ? 0 : 1);
