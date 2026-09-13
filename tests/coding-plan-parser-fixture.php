<?php

declare(strict_types=1);

/**
 * P1-2 解析器 fixture 自测（非 PHPUnit，独立脚本，跑完即退出）
 * 数据取自 docs/upstream-snapshots/2026-09-12/pricing-data.md 的官方原始数值。
 */
require __DIR__.'/../vendor/autoload.php';

use App\Services\CodingPlanParsers\AnthropicParser;
use App\Services\CodingPlanParsers\BaiduParser;
use App\Services\CodingPlanParsers\CmccParser;
use App\Services\CodingPlanParsers\DeepSeekParser;
use App\Services\CodingPlanParsers\GoogleParser;
use App\Services\CodingPlanParsers\MiniMaxParser;
use App\Services\CodingPlanParsers\MoonshotParser;
use App\Services\CodingPlanParsers\OpenAiMarkdownParser;
use App\Services\CodingPlanParsers\ScnetParser;
use App\Services\CodingPlanParsers\SiliconflowParser;
use App\Services\CodingPlanParsers\TencentTokenHubParser;
use App\Services\CodingPlanParsers\UnicomParser;
use App\Services\CodingPlanParsers\VolcengineDocParser;
use App\Services\CodingPlanParsers\XaiMarkdownParser;
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

// ---- xAI（Mintlify .md：Text API Pricing 表；长上下文分档取 < 200k 首档）----
$xMd = <<<'MD'
#### Key Information

# Models

### Text API Pricing

| Model | Context | Input / 1M tokens | Cached input / 1M tokens | Output / 1M tokens |
| --- | --- | --- | --- | --- |
| grok-4.6 (< 200k prompt tokens) | 500k | $2.00 | $0.50 | $6.00 |
| grok-4.6 (≥ 200k prompt tokens) | 500k | $4.00 | $1.00 | $12.00 |
| grok-build-0.1 (< 200k prompt tokens) | 256k | $1.00 | $0.20 | $2.00 |

*Prices shown per million tokens.*

### Imagine Pricing

| Model | Cost |
| --- | --- |
| grok-imagine-image | $0.02 / image |
MD;

$x = new XaiMarkdownParser;
$xEntries = $x->parsePricing($xMd);
$xByModel = [];
foreach ($xEntries as $entry) {
    $xByModel[$entry['model']] = $entry;
}
check('xai 解析 2 条（≥200k 档跳过；Imagine 按次计价表不产条目）', count($xEntries) === 2);
check('grok-4.6 input=0.002（$2/1M → $/1k）', abs(($xByModel['grok-4.6']['input_rate'] ?? 0) - 0.002) < 1e-9);
check('grok-4.6 cached=0.0005、output=0.006', abs(($xByModel['grok-4.6']['cached_rate'] ?? 0) - 0.0005) < 1e-9 && abs(($xByModel['grok-4.6']['output_rate'] ?? 0) - 0.006) < 1e-9);
check('imagine 模型不在结果（非 token 计价）', ! isset($xByModel['grok-imagine-image']));

// ---- 腾讯云 TokenHub（Slate SSR：Model ID 表逐变体拆分 → catalog）----
$tHtml = <<<'HTML'
<tr><td data-slate-node="element"><span data-slate-string="true">Model Name</span></td><td data-slate-node="element"><span data-slate-string="true">Model ID</span></td><td data-slate-node="element"><span data-slate-string="true">说明</span></td></tr>
<tr><td><div><span data-slate-string="true">MiniMax-M2.7</span></div></td><td><div class="tse-markdown-ul"><span class="tse-ul-content"><span data-slate-string="true">minimax-m2.7</span></span></div><div class="tse-markdown-ul"><span class="tse-ul-content"><span data-slate-string="true">minimax-m-2-7</span></span></div></td><td><div><span data-slate-string="true">MiniMax 旗舰</span></div></td></tr>
<tr><td><div><span data-slate-string="true">Auto</span></div></td><td><div><span data-slate-string="true">tc-code-latest</span></div></td><td><div><span data-slate-string="true">智能路由</span></div></td></tr>
<tr><td><div><span data-slate-string="true">Hy Token Plan</span></div></td><td><div><span data-slate-string="true">每订阅月780 积分</span></div></td><td><div><span data-slate-string="true">套餐档位</span></div></td></tr>
<tr><td><div><span data-slate-string="true">WorkBuddy腾讯云全场景 AI 桌面智能体</span></div></td><td><div><span data-slate-string="true">WorkBuddy</span><span data-slate-string="true">腾讯云全场景 AI 桌面智能体</span></div></td><td><div></div></td></tr>
HTML;

$t = new TencentTokenHubParser;
$tCatalog = $t->parseCatalog($tHtml);
check('tencent parsePricing=空（套餐积分配额页）', $t->parsePricing($tHtml) === []);
check('tencent catalog 含双风格变体（minimax-m2.7 与 minimax-m-2-7）', in_array('minimax-m2.7', $tCatalog, true) && in_array('minimax-m-2-7', $tCatalog, true));
check('tencent catalog 含 tc-code-latest', in_array('tc-code-latest', $tCatalog, true));
check('tencent 表头/套餐格/工具生态格不入目录', ! in_array('model id', $tCatalog, true) && ! in_array('workbuddy', $tCatalog, true) && count($tCatalog) === 3);

// ---- Kimi / Moonshot（kimi.ai .md：DocTable JSX rows；列序=命中价在前）----
$kMd = <<<'MD'
# Model Pricing

export const DocTable = ({columns = [], rows = []}) => {
  return <div className=\"doc-table-wrap\"><table className=\"doc-table\"><tbody>
        {rows.map((row, rowIndex) => <tr key={rowIndex}>
              {row.map((cell, cellIndex) => <td key={cellIndex}>{cell}</td>)}
            </tr>)}
        </tbody></table></div>;
};

<DocTable
  columns={[
{ title: \"Model\", width: \"24%\" },
{ title: \"Input (cache hit)\", width: \"16%\" },
{ title: \"Input (cache miss)\", width: \"16%\" },
{ title: \"Output\", width: \"14%\" },
]}
  rows={[
[\"kimi-k3\", \"1M tokens\", <>{\"$\"}0.30</>, <>{\"$\"}3.00</>, <>{\"$\"}15.00</>, \"1,048,576 tokens\"],
[\"kimi-k2.7-code\", \"1M tokens\", <>{\"$\"}0.19</>, <>{\"$\"}0.95</>, <>{\"$\"}4.00</>, \"262,144 tokens\"],
[\"kimi-k2.7-code-highspeed\", \"1M tokens\", <>{\"$\"}0.38</>, <>{\"$\"}1.90</>, <>{\"$\"}8.00</>, \"262,144 tokens\"],
[\"kimi-k2.6\", \"1M tokens\", \"$0.16\", \"$0.95\", \"$4.00\", \"262,144 tokens\"],
]}
/>

Here, 1M = 1,000,000. The prices in the table represent the cost per 1M tokens consumed.
MD;

$k = new MoonshotParser;
$kEntries = $k->parsePricing($kMd);
$kByModel = [];
foreach ($kEntries as $entry) {
    $kByModel[$entry['model']] = $entry;
}
check('moonshot 解析 4 条（JSX 与字符串价混排）', count($kEntries) === 4);
check('kimi-k3 cached=0.0003（列序命中价在前，$/1M → $/1k）', abs(($kByModel['kimi-k3']['cached_rate'] ?? 0) - 0.0003) < 1e-9);
check('kimi-k3 input=0.003（未命中价列）、output=0.015', abs(($kByModel['kimi-k3']['input_rate'] ?? 0) - 0.003) < 1e-9 && abs(($kByModel['kimi-k3']['output_rate'] ?? 0) - 0.015) < 1e-9);
check('kimi-k2.6 纯字符串价行 cached=0.00016', abs(($kByModel['kimi-k2.6']['cached_rate'] ?? 0) - 0.00016) < 1e-9);
check('上下文窗口数字（1,048,576）未污染价格', count($kEntries[0]) === 8 && ($kEntries[0]['input_rate'] ?? null) !== null);

// ---- MiniMax（minimax.io paygo.md：划线价取实价；Priority Tab / Legacy Accordion 剥离；M3 分档取首档）----
$mmMd = <<<'MD'
## LLM

<Tabs>
  <Tab title=\"Standard\">
    | Model | Input | Output | Prompt caching Read |
    | :--- | :--- | :--- | :--- |
    | **MiniMax-M3**<br />≤ 512k input tokens <span>Permanent 50% off</span> | ~~\$0.60~~ \$0.30 / M tokens | ~~\$2.40~~ \$1.20 / M tokens | ~~\$0.12~~ \$0.06 / M tokens |
    | **MiniMax-M3**<br />> 512k input tokens* <span>Permanent 50% off</span> | ~~\$1.20~~ \$0.60 / M tokens | ~~\$4.80~~ \$2.40 / M tokens | ~~\$0.24~~ \$0.12 / M tokens |
  </Tab>

  <Tab title=\"Priority*\">
    | Model | Input | Output | Prompt caching Read |
    | :--- | :--- | :--- | :--- |
    | **MiniMax-M3**<br />≤ 512k input tokens | \$0.90 / M tokens | \$1.80 / M tokens | \$0.09 / M tokens |
  </Tab>
</Tabs>

| Model | Input | Output | Prompt caching Read | Prompt caching Write |
| :--- | :--- | :--- | :--- | :--- |
| **MiniMax-M2.7** | \$0.3 / M tokens | \$1.2 / M tokens | \$0.06 / M tokens | \$0.375 / M tokens |
| **MiniMax-M2.7-highspeed** | \$0.6 / M tokens | \$2.4 / M tokens | \$0.06 / M tokens | \$0.375 / M tokens |

<Accordion title=\"Legacy Models\">
  | Model | Input | Output | Prompt caching Read | Prompt caching Write |
  | :--- | :--- | :--- | :--- | :--- |
  | **MiniMax-M2.5** | \$0.3 / M tokens | \$1.2 / M tokens | \$0.03 / M tokens | \$0.375 / M tokens |
</Accordion>
MD;

$mm = new MiniMaxParser;
$mmEntries = $mm->parsePricing($mmMd);
$mmByModel = [];
foreach ($mmEntries as $entry) {
    $mmByModel[$entry['model']] = $entry;
}
check('minimax 解析 3 条（M3 >512k 档、Priority、Legacy 均不产条目）', count($mmEntries) === 3 && isset($mmByModel['minimax-m3']) && isset($mmByModel['minimax-m2.7']) && isset($mmByModel['minimax-m2.7-highspeed']));
check('minimax-m3 划线价取实价 input=0.0003（$0.30/1M）', abs(($mmByModel['minimax-m3']['input_rate'] ?? 0) - 0.0003) < 1e-9);
check('minimax-m3 output=0.0012、cached=0.00006', abs(($mmByModel['minimax-m3']['output_rate'] ?? 0) - 0.0012) < 1e-9 && abs(($mmByModel['minimax-m3']['cached_rate'] ?? 0) - 0.00006) < 1e-9);
check('minimax-m2.7-highspeed input=0.0006、legacy m2.5 未收', abs(($mmByModel['minimax-m2.7-highspeed']['input_rate'] ?? 0) - 0.0006) < 1e-9 && ! isset($mmByModel['minimax-m2.5']));
check('minimax Caching Write 列不入字段（entry 8 键）', count($mmEntries[1]) === 8);
// ---- baidu（千帆 SSR 价格页：版本名称列连排 id 切分；量包表跳过；CNY→空 pricing）----
$baiduHtml = <<<'BAIDU'
<table><thead><tr><th>模型名称</th><th>版本名称</th><th>服务内容</th><th>子项</th><th>在线推理</th><th>批量推理</th><th>单位</th></tr></thead><tbody>
<tr><td>ERNIE 5.1</td><td>ERNIE-5.1</td><td>推理服务</td><td>输入（输入&lt;=32k）</td><td>0.004</td><td>-</td><td>元/千tokens</td></tr>
<tr><td>ERNIE 5.1</td><td>ERNIE-5.1ERNIE-5.1-Speed-Preview</td><td>推理服务</td><td>输出</td><td>0.018</td><td>-</td><td>元/千tokens</td></tr>
<tr><td>BCE</td><td>bce-embedding-base_v1</td><td>推理服务</td><td>输入</td><td>0.002</td><td>-</td><td>元/千tokens</td></tr>
</tbody></table>
<table><thead><tr><th>量包名称</th><th>量包额度（Tokens）</th><th>服务速率限制</th><th>有效期</th><th>原价(元)</th></tr></thead><tbody>
<tr><td>500万Tokens体验包</td><td>5,000,000</td><td>-</td><td>1个月</td><td>99</td></tr>
</tbody></table>
BAIDU;
$baiduParser = new BaiduParser;
$baiduCatalog = $baiduParser->parseCatalog($baiduHtml);
check('baidu 连排版本名称切分 + 量包表跳过', $baiduCatalog === ['ernie-5.1', 'ernie-5.1-speed-preview', 'bce-embedding-base_v1']);
check('baidu parsePricing 空（元/千 tokens CNY→P7）', $baiduParser->parsePricing($baiduHtml) === []);

// ---- volcengine（doccenter getDocDetail API：Content=Quill delta JSON 字符串，逐 zone 提 doubao-*）----
// 双层 JSON（API 响应包裹 delta 字符串）用 json_encode 构造，避免手写嵌套转义
$volcDelta = ['version' => '0.4.24', 'data' => [
    '0' => ['zoneType' => 'Z', 'ops' => [
        ['insert' => '输入 2.4 元/百万 token，输出 24 元/百万 token。'],
        ['insert' => 'doubao-seed-1.6 系列支持分段计费，doubao-seedance-2.5 为视频模型。'],
    ]],
    'xrA' => ['zoneType' => 'C', 'ops' => [['insert' => 'doubao-seed-1.6-vision']]],
    'xrB' => ['zoneType' => 'C', 'ops' => [['insert' => 'doubao-1.5-thinking-pro.']]],
]];
$volcBody = json_encode(['Result' => ['Content' => json_encode($volcDelta, JSON_UNESCAPED_UNICODE)]], JSON_UNESCAPED_UNICODE);
$volcParser = new VolcengineDocParser;
$volcCatalog = $volcParser->parseCatalog($volcBody);
check('volcengine Quill delta 逐 zone 提取（正文+cell）', $volcCatalog === ['doubao-seed-1.6', 'doubao-seedance-2.5', 'doubao-seed-1.6-vision', 'doubao-1.5-thinking-pro']);
check('volcengine 叙述句不整体入库（模型 id 精准切分）', ! in_array('doubao-seed-1.6 系列支持分段计费，doubao-seedance-2.5 为视频模型。', $volcCatalog, true));
check('volcengine parsePricing 空（元/百万 token CNY→P7）', $volcParser->parsePricing($volcBody) === []);
check('volcengine 非 API 响应（壳 HTML）安全返回空', $volcParser->parseCatalog('<html>doc center shell</html>') === []);

// ---- unicom（DedeCMS 页面内嵌 totalList 全站文档树：抽 7015/7080 两篇「支持模型」列）----
$unicomCoding = '<table><tr><th>云区域</th><th>支持模型</th><th>BaseURL</th></tr>'
    .'<tr><td>贵阳基地二区</td><td>aisp-auto-route：智能路由，系统通过算法自动匹配当前最优模型<br />DeepSeek-V4-Flash</td><td>https://aigw-gzgy2.cucloud.cn:8443/v1</td></tr>'
    .'<tr><td>贵阳基地二区</td><td>glm-5.1、glm-5</td><td>同上</td></tr>'
    .'<tr><td>贵阳基地二区</td><td>kimi-k2.6、kimi-k2.5</td><td>同上</td></tr>'
    .'<tr><td colspan="3">注： DeepSeek-V4-Flash&nbsp;仅供尝鲜体验，上下文窗口目前仅支持 200K。</td></tr></table>';
$unicomToken = '<table><tr><th>云区域</th><th>云区域支持套餐类型</th><th>支持模型</th><th>BaseURL</th></tr>'
    .'<tr><td>贵阳基地二区</td><td>Token Plan 团队版</td><td>DeepSeek-V4-Pro<br />MiniMax-M2.5</td><td>https://aigw-gzgy2.cucloud.cn:8443/v1</td></tr>'
    .'<tr><td>武汉四区</td><td>Token Plan 个人版</td><td>DeepSeek-V4-Flash<br />MiniMax-M2.5</td><td>同上</td></tr></table>';
$unicomPage = '<html><script>var totalList = '.json_encode([
    ['childList' => [
        ['documentEntityList' => [['id' => '7015', 'title' => 'Coding Plan概述', 'content' => $unicomCoding]]],
        ['documentEntityList' => [['id' => '7080', 'title' => 'Token Plan概述', 'content' => $unicomToken]]],
    ]],
], JSON_UNESCAPED_UNICODE).';</script></html>'; // JSON 自带 root "]", 后接语句 ";" —— 与真实页面 "totalList = [...];" 一致
$unicomParser = new UnicomParser;
$unicomCatalog = $unicomParser->parseCatalog($unicomPage);
check('unicom 内嵌文档树提取 + 两篇「支持模型」列合并', $unicomCatalog === ['aisp-auto-route', 'deepseek-v4-flash', 'glm-5.1', 'glm-5', 'kimi-k2.6', 'kimi-k2.5', 'deepseek-v4-pro', 'minimax-m2.5']);
check('unicom 注释行/中文说明不入目录', ! in_array('注', $unicomCatalog, true) && ! in_array('deepseek-v4-flash 仅供尝鲜体验', $unicomCatalog, true));
check('unicom parsePricing 空（套餐档位 CNY 次数/credits→P2-1/P7）', $unicomParser->parsePricing($unicomPage) === []);
check('unicom 无 totalList 的壳页安全返回空', $unicomParser->parseCatalog('<html>shell</html>') === []);

// ---- cmcc（移动云 CMS API 两步抓取第二步：正文裸 HTML；id 列=规格名称/模型名称右列）----
// 真结构缩减版（91592「Token按量计费-自营模型」）：文本表 id 同格逗号/顿号并列、
// rowspan 续行格为 &nbsp;、视频表 id 在「资费场景」列（系列名占「模型名称」列）。
$cmccBody = '<p>夜间资费（00:00‑08:00）仅限按量模式的模型调用，Token 资源包调用不参与夜间优惠。</p>'
    .'<table><tr><th>模型名称</th><th>规格名称</th><th>输入/输出tokens</th><th>单价（元/百万tokens）</th></tr>'
    .'<tr><td>DeepSeek系列</td><td>DeepSeek-R1,DeepSeek-R1-0528&nbsp;</td><td>输入tokens<br />输出tokens</td><td>4<br />16</td></tr>'
    .'<tr><td>&nbsp;</td><td>&nbsp;</td><td>&nbsp;</td><td>&nbsp;</td></tr>'
    .'<tr><td>DeepSeek-V3、DeepSeek-V3-0324、DeepSeek-V3.1、DeepSeek-V3.2</td><td>DeepSeek-V3&nbsp;</td><td>输入tokens 输出tokens</td><td>2 8</td></tr>'
    .'<tr><td>Qwen系列</td><td>Qwen3.5-35B-A3B</td><td>输入tokens 输出tokens</td><td>0.8 2</td></tr></table>'
    .'<table><tr><th>模型名称</th><th>资费场景</th><th>单价</th></tr>'
    .'<tr><td>MiniMax系列</td><td>MiniMax-H3</td><td>视频输入（768P）</td><td>0.5元/秒</td></tr>'
    .'<tr><td>&nbsp;</td><td>图片输入</td><td>&nbsp;</td><td>0.2元/张（前5张免费）</td></tr></table>'
    .'<table><tr><th>量包名称</th><th>额度</th><th>原价(元)</th></tr><tr><td>体验包</td><td>100万tokens</td><td>9.9</td></tr></table>';
$cmccParser = new CmccParser;
$cmccCatalog = $cmccParser->parseCatalog($cmccBody);
check('cmcc 规格名称列拆分（逗号/顿号）+ 视频表回落模型名称右列 + 量包表跳过', $cmccCatalog === ['deepseek-r1', 'deepseek-r1-0528', 'deepseek-v3', 'qwen3.5-35b-a3b', 'minimax-h3']);
check('cmcc 系列名/中文格不入目录', ! in_array('deepseek系列', $cmccCatalog, true) && ! in_array('minimax系列', $cmccCatalog, true));
check('cmcc parsePricing 空（元/百万 tokens CNY→P3-4）', $cmccParser->parsePricing($cmccBody) === []);
check('cmcc SPA 壳/非正文安全返回空', $cmccParser->parseCatalog('<!doctype html><div id="app"></div>') === []);

// ---- scnet（超算互联网 SCNet，VitePress SSR：「可用模型」表按表头「模型ID」定位列）----
$scnetPage = '<main><p>Token Plan 是超算互联网（SCNet）的大模型包月订阅服务（Credits 计量）。</p>'
    .'<table><thead><tr><th><strong>套餐</strong></th><th><strong>原价（¥/月）</strong></th><th><strong>活动价（¥/月）</strong></th><th><strong>月度额度</strong></th></tr></thead>'
    .'<tbody><tr><td>基础版</td><td>¥50</td><td>¥30</td><td>60,000 Credits</td></tr><tr><td>旗舰版</td><td>¥1274</td><td>¥764</td><td>1,800,000 Credits</td></tr></tbody></table>'
    .'<table tabindex="0"><thead><tr><th><strong>品牌</strong></th><th><strong>模型ID</strong></th><th><strong>模型能力</strong></th><th><strong>支持协议</strong></th></tr></thead><tbody>'
    .'<tr><td>智谱AI</td><td>GLM-5.3</td><td>文本生成、深度思考</td><td>OpenAI、Anthropic</td></tr>'
    .'<tr><td>DeepSeek</td><td>DeepSeek-V4-Pro-0813</td><td>文本生成、深度思考</td><td>OpenAI、Anthropic</td></tr>'
    .'<tr><td>月之暗面</td><td>Kimi-K3</td><td>文本生成、深度思考</td><td>OpenAI、Anthropic</td></tr>'
    .'<tr><td>MiniMax</td><td>MiniMax-M3</td><td>文本生成</td><td>OpenAI</td></tr>'
    .'</tbody></table>'
    .'<table><thead><tr><th><strong>模型名称</strong></th><th><strong>2026年9月1日扣减倍率</strong></th></tr></thead><tbody><tr><td>GLM-5.3</td><td>2.29</td></tr><tr><td>Kimi-K3</td><td>4.12</td></tr></tbody></table>'
    .'</main>';
$scnetParser = new ScnetParser;
check('scnet「可用模型」表抽「模型ID」列（跳过档位/倍率表）', $scnetParser->parseCatalog($scnetPage) === ['glm-5.3', 'deepseek-v4-pro-0813', 'kimi-k3', 'minimax-m3']);
check('scnet parsePricing 空（Credits 套餐 CNY→P2-1/P7）', $scnetParser->parsePricing($scnetPage) === []);
check('scnet 无表格页面安全返回空', $scnetParser->parseCatalog('<main><p>empty</p></main>') === []);

// ---- siliconflow（硅基流动 SSR 价目页：<a title="org/model">，Pro/ 前缀=加速标记）----
$sfPage = '<div class="pricing-row-text-17885302869" class="grid"><a href="https://cloud.siliconflow.cn/models?target=tencent%2FHunyuan-A13B-Instruct" title="tencent/Hunyuan-A13B-Instruct">Hunyuan-A13B-Instruct</a>'
    .'<span>费用发生时段: 9点～18点</span></div>'
    .'<a title="zai-org/GLM-5.3">GLM-5.3</a>'
    .'<a title="Pro/zai-org/GLM-5.1">GLM-5.1</a>'
    .'<a title="deepseek-ai/DeepSeek-V4-Pro">DeepSeek-V4-Pro</a>'
    .'<a href="/docs" title="使用文档">使用文档</a>'
    .'<a title="plain">无斜杠非模型</a>';
$sfParser = new SiliconflowParser;
check('siliconflow 抽 org/model 全 id、剥 Pro/ 前缀、跳非模型链接', $sfParser->parseCatalog($sfPage) === ['tencent/hunyuan-a13b-instruct', 'zai-org/glm-5.3', 'zai-org/glm-5.1', 'deepseek-ai/deepseek-v4-pro']);
check('siliconflow parsePricing 空（¥/M tokens→P7-2/P7-3）', $sfParser->parsePricing($sfPage) === []);
check('siliconflow 空页安全返回空', $sfParser->parseCatalog('<html></html>') === []);

echo $fail === 0 ? "\n✅ 解析器 fixture 全部通过\n" : "\n❌ {$fail} 项失败\n";
exit($fail === 0 ? 0 : 1);
