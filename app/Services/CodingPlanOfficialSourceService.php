<?php

declare(strict_types=1);

namespace App\Services;

use App\Console\Commands\VerifyCodingPlanRatios;
use App\Models\CodingPlanModelRatio;
use App\Models\CodingPlanRatioCheck;
use App\Services\CodingPlanParsers\AnthropicParser;
use App\Services\CodingPlanParsers\BaiduParser;
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
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;

/**
 * Coding Plan 官方源同步服务（P1-1 / P1-4 / P1-5 / P1-8）
 *
 * 职责（verify-ratios 之外的补全 —— verify 只处理「结构化 JSON 定价源」，
 * 本服务覆盖官方 HTML/Markdown 页面的抓取、解析、快照与 diff 编排）：
 *  1. 源注册表：15 家厂商的官方定价/目录 URL、格式、解析器、是否需代理（P1-1）；
 *  2. 抓取：统一 UA/Accept-Language，命中 PEASE_API_HTTP_PROXY 时仅对
 *     proxy=true 的境外源走代理，国内源直连（P1-8）；
 *  3. 快照：storage/app/coding-plan-snapshots/{vendor}/{Y-m-d-Hi}.json
 *     （+ 解析失败时的 .raw.txt 原始响应），每厂商保留最近 10 份（P1-4）；
 *  4. 复用 diff：normalize/diff 逻辑自 VerifyCodingPlanRatios 抽取（P1-5），
 *     输出与校对流水同一约定（new/changed/missing + pending_keys + 忽略标记），
 *     模型目录额外产出 kind=model_catalog（P1-6 上架流数据源）。
 *
 * 只记录、不自动改价 —— 改错比率等于资损，变更须人工确认。
 */
class CodingPlanOfficialSourceService
{
    /** 快照存储盘与目录（相对 storage/app） */
    public const SNAPSHOT_DISK = 'local';

    public const SNAPSHOT_DIR = 'coding-plan-snapshots';

    /** 每厂商快照保留份数 */
    public const SNAPSHOT_KEEP = 10;

    /** 抓取代理环境变量（仅 proxy=true 的源使用） */
    public const PROXY_ENV = 'PEASE_API_HTTP_PROXY';

    /**
     * 内置官方源注册表（2026-09-12 快照核实）：
     *  - pricing_url/model_catalog_url：null = 待定位（SPA 探测中，见 docs/upstream-snapshots）；
     *  - format：html | markdown | json | spa | mintlify；
     *  - parser：解析器类名，null = 适配器待补（P1-2），仅存原始快照；
     *  - proxy：true = 境外源，走 PEASE_API_HTTP_PROXY；
     *  - notes：抓取要点（双价切换/SPA 探测方向等）。
     */
    protected array $registry = [
        'deepseek' => [
            'label' => 'DeepSeek',
            'pricing_url' => 'https://api-docs.deepseek.com/zh-cn/quick_start/pricing/',
            'format' => 'html',
            'parser' => DeepSeekParser::class,
            'proxy' => false,
            'model_catalog_url' => null,
            'notes' => 'SSR 表格（空闲/高峰双价）；V4 Pro 延续声明 2026-09-14 后计费不变',
        ],
        'openai' => [
            'label' => 'OpenAI',
            'pricing_url' => 'https://platform.openai.com/docs/pricing.md',
            'format' => 'markdown',
            'parser' => OpenAiMarkdownParser::class,
            'proxy' => true,
            'model_catalog_url' => 'https://platform.openai.com/docs/models.md',
            'notes' => '.md 后缀直取 Markdown；GPT-5.6 Sol 促销至少至 2026-11-21；Batch 5 折',
        ],
        'google' => [
            'label' => 'Google Gemini',
            'pricing_url' => 'https://ai.google.dev/gemini-api/docs/pricing',
            'format' => 'html',
            'parser' => GoogleParser::class,
            'proxy' => true,
            'model_catalog_url' => null,
            'notes' => '中文机翻 SSR；模型名在锚点 id、表在层级子标题下（只取 standard）；基础价=恢复价（2027-01-01 起长期价），促销价归 P2 promotions',
        ],
        'anthropic' => [
            'label' => 'Anthropic',
            'pricing_url' => 'https://docs.anthropic.com/en/docs/about-claude/pricing',
            'format' => 'html',
            'parser' => AnthropicParser::class,
            'proxy' => true,
            'model_catalog_url' => null,
            'notes' => 'Next.js SSR div 表格（平铺 <tr> 扫描）；显示名转 id；Batch/1M 长上下文表与 CCU 说明跳过',
        ],
        'xai' => [
            'label' => 'xAI Grok',
            'pricing_url' => 'https://docs.x.ai/developers/models.md',
            'format' => 'markdown',
            'parser' => XaiMarkdownParser::class,
            'proxy' => true,
            'model_catalog_url' => null,
            'notes' => '.md 直取（/docs/models 308 → /developers/models）；长上下文分档取 < 200k 首档；Imagine/Voice 按次计价表跳过',
        ],
        'zhipu' => [
            'label' => '智谱 BigModel',
            'pricing_url' => 'https://docs.bigmodel.cn/cn/coding-plan/overview.md',
            'format' => 'markdown',
            'parser' => ZhipuMarkdownParser::class,
            'proxy' => false,
            'model_catalog_url' => null,
            'catalog_from_pricing' => true,
            'notes' => 'Mintlify .md 直取；页面=套餐积分（非模型价，parsePricing 空）+ GLM 覆盖模型清单（catalog_from_pricing 产 model_catalog）；高峰窗口=周一至五 14:00-18:00（已预置 000009）',
        ],
        'aliyun' => [
            'label' => '阿里云百炼',
            'pricing_url' => 'https://docs.bailian.console.aliyun.com/llms-full.txt',
            'format' => 'markdown',
            'parser' => null,
            'proxy' => false,
            'model_catalog_url' => null,
            'notes' => '源重定位（2026-09-12）：help.aliyun.com 旧页 404 → docs.bailian llms.txt/llms-full.txt 可抓（单页 .md 被 WAF 拦需 X-Request-Context）；内容=Token Plan 个人/团队套餐档位（39/139/499 元，CNY）+用量包，无按量 token 价 → 归 P2-1 套餐档 + P7 币种字段',
        ],
        'tencent' => [
            'label' => '腾讯云 TokenHub',
            'pricing_url' => 'https://cloud.tencent.com/document/product/1823/130060',
            'format' => 'html',
            'parser' => TencentTokenHubParser::class,
            'proxy' => false,
            'model_catalog_url' => null,
            'catalog_from_pricing' => true,
            'notes' => 'Slate SSR；套餐积分配额页无按量价 → catalog_from_pricing 产 model_catalog（Model ID 表逐变体拆分）；GLM-5/5.1 于 2026-10-09 下线（P2-2 model_retirement）',
        ],
        'siliconflow' => [
            'label' => 'SiliconFlow 硅基流动',
            'pricing_url' => 'https://siliconflow.cn/pricing',
            'format' => 'html',
            'parser' => SiliconflowParser::class,
            'proxy' => false,
            'model_catalog_url' => null,
            'catalog_from_pricing' => true,
            'notes' => 'SSR 直出（~225KB）：pricing-row-{kind} 行 + <a title="org/model">（52 款，含 text/image/audio/video 全模态；Pro/ 前缀=加速标记需剥）→ catalog；按量价 ¥/M tokens（CNY，双时段「9点～18点」价组）→ 折算比率归 P7-2/P7-3（时段价可映射 time_discounts）',
        ],
        'moonshot' => [
            'label' => 'Kimi / Moonshot',
            'pricing_url' => 'https://platform.kimi.ai/docs/pricing/chat.md',
            'format' => 'markdown',
            'parser' => MoonshotParser::class,
            'proxy' => true,
            'model_catalog_url' => null,
            'notes' => 'moonshot.cn 301 → platform.kimi.com；llms.txt 索引 + .md 直取；注册国际站 kimi.ai（$，DocTable JSX rows），中文站为 ¥ 不配；列序命中价在前；batch/tools 独立页不配',
        ],
        'volcengine' => [
            'label' => '火山方舟',
            'pricing_url' => 'https://docs.volcengine.com/api/doc/getDocDetail?DocumentID=1544106&lang=zh',
            'format' => 'json',
            'parser' => VolcengineDocParser::class,
            'proxy' => false,
            'model_catalog_url' => null,
            'catalog_from_pricing' => true,
            'notes' => 'doccenter SPA → 前端 bundle 挖出 XHR API getDocDetail（旧 doc 1099320 已 301 → 1544106 model-pricing）；Content=Quill delta（Z 正文/R 行/C cell/x* cell 文本）→ catalog 提取 doubao-*；按量价元/百万 token（CNY）→ 三率归 P7-1',
        ],
        'unicom' => [
            'label' => '联通云',
            'pricing_url' => 'https://support.cucloud.cn/document/127/591/2357.html?id=2357&arcid=7015',
            'format' => 'html',
            'parser' => UnicomParser::class,
            'proxy' => false,
            'model_catalog_url' => null,
            'catalog_from_pricing' => true,
            'notes' => '无 XHR——文档树+全部正文内嵌页面 JS（totalList=全站树 16.5MB，documentEntityList[].content=富文本 HTML，id=arcid；7015=Coding Plan概述、7080=Token Plan概述）→ 抽两篇「支持模型」列（顿号/空白拆分，「别名：说明」取冒号前段）→ catalog；套餐档位（Lite 40/Pro 200 元/月，Token Plan 15/30/45、团队 198/698/1398 元 CNY，量纲=次数/credits）→ 归 P2-1，credits 折算综合单价（元/百万 tokens CNY）仅参考 → 币种归 P7-1',
        ],
        'cmcc' => [
            'label' => '移动云',
            'pricing_url' => null,
            'format' => 'spa',
            'parser' => null,
            'proxy' => false,
            'model_catalog_url' => null,
            'notes' => 'React 壳（cloud-cms-service-web）→ API 网关已定位（/api/web/op-help-center/request-api/{record,service}-api/…，40 webpack chunks 全查）；文档读取 API 全部经 OIDC 认证（匿名 curl 302 → iam/oidc/authorize?client_id=opgateway）——端点存在、需登录态，匿名抓取不可行 → 预置数据（迁移 000008）覆盖 P1',
        ],
        'minimax' => [
            'label' => 'MiniMax',
            'pricing_url' => 'https://platform.minimax.io/docs/guides/pricing-paygo.md',
            'format' => 'markdown',
            'parser' => MiniMaxParser::class,
            'proxy' => true,
            'model_catalog_url' => null,
            'notes' => 'llms.txt + .md 直取；注册国际站 minimax.io（$，国内 minimaxi.com 为 ¥ 不配）；Priority Tab（1.5x 条件价）/Legacy Accordion 整段剥离；M3 分档取 ≤512k 首档；划线价取实价；Token Plan 套餐页归 P2-1',
        ],
        'baidu' => [
            'label' => '百度千帆',
            'pricing_url' => 'https://cloud.baidu.com/doc/qianfan/s/wmh4sv6ya',
            'format' => 'html',
            'parser' => BaiduParser::class,
            'proxy' => false,
            'model_catalog_url' => null,
            'catalog_from_pricing' => true,
            'notes' => '旧 doc hlpl7xe2f 已 302 → 从 index 定位新页 qianfan/s/wmh4sv6ya（415KB SSR，217 行价格表）；「版本名称」列=API id（连排格按 ernie-/bce- 前缀切分）→ catalog；按量价元/千 tokens（CNY，单位天然 /千无需换算）→ 三率归 P7-1',
        ],
        'scnet' => [
            'label' => '超算互联网 SCNet',
            'pricing_url' => 'https://www.scnet.cn/ac/openapi/doc/2.0/moduleapi/plans/token-plan.html',
            'format' => 'html',
            'parser' => ScnetParser::class,
            'proxy' => false,
            'model_catalog_url' => null,
            'catalog_from_pricing' => true,
            'notes' => 'VitePress 文档站 SSR 直出正文（无需挖 JS/XHR）：「可用模型」表（品牌|模型ID|能力|协议，18 款国产模型）按表头「模型ID」定位列 → catalog；套餐档位（基础/标准/高级/旗舰 ¥30~764 活动价 CNY，月度 Credits 额度）→ 归 P2-1；Credits 扣减倍率表 → 折算系数仅参考归 P7-1',
        ],
    ];

    public function registry(): array
    {
        return $this->registry;
    }

    public function builtInSource(string $vendor): ?array
    {
        return $this->registry[$vendor] ?? null;
    }

    /**
     * 解析厂商抓取源：管理端 pricing_source_url（结构化 JSON 约定）优先，
     * 否则回落内置注册表。内置源解析器未就绪时 parser=null（仅存原始快照）。
     *
     * @return array{url: string, kind: string, format: string, parser: ?string, proxy: bool, model_catalog_url: ?string, catalog_from_pricing: bool, notes: string}|null
     */
    public function resolveSource(?string $adminUrl, string $vendor): ?array
    {
        if (is_string($adminUrl) && trim($adminUrl) !== '') {
            return [
                'url' => trim($adminUrl),
                'kind' => 'structured',
                'format' => 'json',
                'parser' => null,
                'proxy' => false,
                'model_catalog_url' => null,
                'catalog_from_pricing' => false,
                'notes' => '管理端配置的结构化定价源',
            ];
        }

        $builtIn = $this->builtInSource($vendor);
        if ($builtIn === null || ($builtIn['pricing_url'] ?? null) === null) {
            return null;
        }

        return [
            'url' => (string) $builtIn['pricing_url'],
            'kind' => 'builtin',
            'format' => (string) ($builtIn['format'] ?? 'html'),
            'parser' => $builtIn['parser'] ?? null,
            'proxy' => (bool) ($builtIn['proxy'] ?? false),
            'model_catalog_url' => $builtIn['model_catalog_url'] ?? null,
            'catalog_from_pricing' => (bool) ($builtIn['catalog_from_pricing'] ?? false),
            'notes' => (string) ($builtIn['notes'] ?? ''),
        ];
    }

    /**
     * 抓取响应体；失败（网络异常/非 2xx）返回 null。
     * proxy=true 且配置了 PEASE_API_HTTP_PROXY 时走代理，国内源直连。
     */
    public function fetchBody(string $url, bool $proxy = false, bool $json = false): ?string
    {
        $options = [];
        if ($proxy) {
            $proxyUrl = trim((string) env(self::PROXY_ENV, ''));
            if ($proxyUrl !== '') {
                $options['proxy'] = $proxyUrl;
            }
        }

        try {
            $client = Http::timeout(20)->connectTimeout(8)->withHeaders([
                'User-Agent' => 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/126.0.0.0 Safari/537.36',
                'Accept-Language' => 'zh-CN,zh;q=0.9,en;q=0.8',
            ]);
            if ($options !== []) {
                $client = $client->withOptions($options);
            }
            if ($json) {
                $client = $client->acceptJson();
            }
            $response = $client->get($url);
        } catch (\Throwable) {
            return null;
        }

        return $response->successful() ? $response->body() : null;
    }

    /**
     * ============ 快照持久化（P1-4） ============
     * storage/app/coding-plan-snapshots/{vendor}/{Y-m-d-Hi}.json
     * 解析失败时另存 {Y-m-d-Hi}.raw.txt 原始响应（修解析器的第一手材料）。
     */
    protected function snapshotDir(string $vendor): string
    {
        return self::SNAPSHOT_DIR.'/'.$vendor;
    }

    /**
     * 保存快照（payload 原样 JSON 化；rawBody 提供时一并存原始响应），返回快照路径。
     */
    public function saveSnapshot(string $vendor, array $payload, ?string $rawBody = null): string
    {
        $stamp = now()->format('Y-m-d-Hi');
        $disk = Storage::disk(self::SNAPSHOT_DISK);
        $path = $this->snapshotDir($vendor).'/'.$stamp.'.json';

        $disk->put($path, json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT));
        if ($rawBody !== null && $rawBody !== '') {
            $disk->put($this->snapshotDir($vendor).'/'.$stamp.'.raw.txt', $rawBody);
        }
        $this->pruneSnapshots($vendor);

        return $path;
    }

    /**
     * 列出快照（新→旧），可选限量。
     *
     * @return list<string>
     */
    public function listSnapshots(string $vendor, ?int $limit = null): array
    {
        $files = collect(Storage::disk(self::SNAPSHOT_DISK)->files($this->snapshotDir($vendor)))
            ->filter(fn (string $file): bool => str_ends_with($file, '.json'))
            ->sortDesc()
            ->values();

        return $limit === null ? $files->all() : $files->take($limit)->all();
    }

    /**
     * 最近一份快照 payload（抓取失败时「沿用上次快照」的数据源）。
     */
    public function loadLatestSnapshot(string $vendor): ?array
    {
        $latest = $this->listSnapshots($vendor, 1)[0] ?? null;
        if ($latest === null) {
            return null;
        }

        $payload = json_decode(Storage::disk(self::SNAPSHOT_DISK)->get($latest) ?? '', true);

        return is_array($payload) ? $payload : null;
    }

    /**
     * 每厂商仅保留最近 $keep 份 .json（连带删除对应 .raw.txt），返回删除数。
     */
    public function pruneSnapshots(string $vendor, int $keep = self::SNAPSHOT_KEEP): int
    {
        $keep = max(1, $keep);
        $disk = Storage::disk(self::SNAPSHOT_DISK);
        $jsonFiles = $this->listSnapshots($vendor);

        $removed = 0;
        foreach (array_slice($jsonFiles, $keep) as $old) {
            $disk->delete($old);
            $raw = substr($old, 0, -5).'.raw.txt';
            if ($disk->exists($raw)) {
                $disk->delete($raw);
            }
            $removed++;
        }

        return $removed;
    }

    /**
     * ============ 结构化源 normalize + diff（P1-5，自 VerifyCodingPlanRatios 抽取） ============
     */

    /**
     * 结构化定价源 JSON → 标准化条目（key = model|match_type）。
     * 接受 {"models":[...]} 包裹或直接数组；无法解析出条目返回 []（视为源异常）。
     *
     * @return array<string, array<string, mixed>>
     */
    public function normalizeStructuredEntries(mixed $payload): array
    {
        $entries = is_array($payload)
            ? (array_key_exists('models', $payload) && is_array($payload['models']) ? $payload['models'] : $payload)
            : null;
        if (! is_array($entries)) {
            return [];
        }

        $source = [];
        foreach ($entries as $entry) {
            if (! is_array($entry)) {
                continue;
            }
            $model = $entry['model'] ?? null;
            if (! is_string($model) || $model === '') {
                continue;
            }
            $matchType = in_array($entry['match_type'] ?? null, [CodingPlanModelRatio::MATCH_EXACT, CodingPlanModelRatio::MATCH_PREFIX], true)
                ? $entry['match_type']
                : CodingPlanModelRatio::MATCH_EXACT;
            // 分段折算条目：unit_cost 可省略，以 input_rate/cached_rate/output_rate 为准
            $isSplit = ($entry['cost_mode'] ?? null) === CodingPlanModelRatio::COST_PER_TOKEN_PARTS;
            $hasSplitRates = isset($entry['input_rate'], $entry['cached_rate'], $entry['output_rate'])
                && is_numeric($entry['input_rate'])
                && is_numeric($entry['cached_rate'])
                && is_numeric($entry['output_rate']);
            if ($isSplit) {
                if (! $hasSplitRates) {
                    continue;
                }
            } elseif (! isset($entry['unit_cost']) || ! is_numeric($entry['unit_cost'])) {
                continue;
            }
            $source[$model.'|'.$matchType] = [
                'model' => $model,
                'match_type' => $matchType,
                'cost_mode' => $isSplit ? CodingPlanModelRatio::COST_PER_TOKEN_PARTS : null,
                'unit_cost' => $isSplit ? null : (float) $entry['unit_cost'],
                'input_rate' => $hasSplitRates ? (float) $entry['input_rate'] : null,
                'cached_rate' => $hasSplitRates ? (float) $entry['cached_rate'] : null,
                'output_rate' => $hasSplitRates ? (float) $entry['output_rate'] : null,
                // 分时段折扣窗口（可选）：规范化失败视为未提供
                'time_discounts' => CodingPlanModelRatio::normalizeTimeDiscounts($entry['time_discounts'] ?? null),
            ];
        }

        return $source;
    }

    /**
     * 拉取并规范化结构化定价源（管理端 pricing_source_url）。
     *
     * @return array{0: int, 1: array<string, array<string, mixed>>|null} [SOURCE_*, 条目|null]
     */
    public function fetchStructuredSource(?string $url): array
    {
        if ($url === null || $url === '') {
            return [CodingPlanRatioCheck::SOURCE_NONE, null];
        }

        $body = $this->fetchBody($url, false, true);
        if ($body === null) {
            return [CodingPlanRatioCheck::SOURCE_FAILED, null];
        }

        $source = $this->normalizeStructuredEntries(json_decode($body, true));
        if ($source === []) {
            return [CodingPlanRatioCheck::SOURCE_FAILED, null];
        }

        return [CodingPlanRatioCheck::SOURCE_OK, $source];
    }

    /**
     * 源条目 vs 库内比率 diff（新模型 / 单位成本或三率或时段窗口变化 / 源中消失）。
     * 只报告，不落库；返回的 buckets 仅保留非空项。
     *
     * @param  array<string, array<string, mixed>>  $source
     * @param  Collection<int, CodingPlanModelRatio>  $ratios
     * @return array<string, list<array<string, mixed>>>
     */
    public function diffEntries(array $source, Collection $ratios): array
    {
        /** @var Collection<string, CodingPlanModelRatio> $current */
        $current = $ratios->keyBy(fn (CodingPlanModelRatio $r) => $r->model.'|'.$r->match_type);

        $new = [];
        $changed = [];
        foreach ($source as $item) {
            // key 从条目内容重算：结构化源为 keyed map、解析器输出为 list，两者兼容
            $key = $item['model'].'|'.$item['match_type'];
            $existing = $current->get($key);
            if ($existing === null) {
                $new[] = $item;

                continue;
            }

            // 停用行（status=0）不参与计费：存在性已被上方 new 判定吸收（停用 ≠ 新增），
            // 数值/时段窗口变化也不再报（对启用行才报，避免停用模型刷噪音）
            if ((int) $existing->status !== 1) {
                continue;
            }

            // 分时段折扣窗口 diff（与三率/单位成本独立比较）
            if ($item['time_discounts'] !== null) {
                $fromWindows = is_array($existing->time_discounts) ? $existing->time_discounts : null;
                if (json_encode($fromWindows) !== json_encode($item['time_discounts'])) {
                    $changed[] = [
                        'model' => $item['model'],
                        'match_type' => $item['match_type'],
                        'kind' => 'time_discounts',
                        'from' => $fromWindows,
                        'to' => $item['time_discounts'],
                    ];
                }
            }

            if ($item['cost_mode'] === CodingPlanModelRatio::COST_PER_TOKEN_PARTS) {
                $diff = max(
                    abs((float) $existing->input_rate - (float) $item['input_rate']),
                    abs((float) $existing->cached_rate - (float) $item['cached_rate']),
                    abs((float) $existing->output_rate - (float) $item['output_rate'])
                );
                if ($diff >= 0.0001) {
                    $changed[] = [
                        'model' => $item['model'],
                        'match_type' => $item['match_type'],
                        'kind' => 'split_rates',
                        'from' => [(float) $existing->input_rate, (float) $existing->cached_rate, (float) $existing->output_rate],
                        'to' => [$item['input_rate'], $item['cached_rate'], $item['output_rate']],
                    ];
                }

                continue;
            }
            if (abs((float) $existing->unit_cost - (float) $item['unit_cost']) >= 0.0001) {
                $changed[] = [
                    'model' => $item['model'],
                    'match_type' => $item['match_type'],
                    'from' => (float) $existing->unit_cost,
                    'to' => $item['unit_cost'],
                ];
            }
        }

        // 源中消失的 exact 条目（仅当源与比率表确有交集时才报告，避免命名空间不同的源误报）
        $missing = [];
        $hasOverlap = $current->contains(fn (CodingPlanModelRatio $r) => isset($source[$r->model.'|'.$r->match_type]));
        if ($hasOverlap) {
            foreach ($current as $key => $ratio) {
                // 仅启用行参与「源中消失」判定（停用行无需提示下架）
                if ($ratio->match_type === CodingPlanModelRatio::MATCH_EXACT
                    && (int) $ratio->status === 1
                    && ! isset($source[$key])) {
                    $missing[] = ['model' => $ratio->model, 'match_type' => $ratio->match_type];
                }
            }
        }

        $changes = [];
        if ($new !== []) {
            $changes['new'] = $new;
        }
        if ($changed !== []) {
            $changes['changed'] = $changed;
        }
        if ($missing !== []) {
            $changes['missing'] = $missing;
        }

        return $changes;
    }

    /**
     * 官方模型目录 vs 库内 exact 比率行 diff → kind=model_catalog 变更
     * （P8 上架流数据源：new=官方有库内无，missing=库内有官方无）。
     *
     * @param  list<string>  $officialModels
     * @param  Collection<int, CodingPlanModelRatio>  $ratios
     * @return array{new: list<array{model: string, match_type: string, change: string}>, missing: list<array{model: string, match_type: string, change: string}>}
     */
    public function diffCatalogModels(array $officialModels, Collection $ratios): array
    {
        $official = [];
        foreach ($officialModels as $model) {
            if (is_string($model) && $model !== '') {
                $official[mb_strtolower($model)] = true;
            }
        }

        $stored = [];
        foreach ($ratios as $ratio) {
            if ($ratio->match_type === CodingPlanModelRatio::MATCH_EXACT) {
                $stored[mb_strtolower($ratio->model)] = true;
            }
        }

        $toItems = fn (array $models): array => array_map(
            fn (string $model): array => ['model' => $model, 'match_type' => '', 'change' => null],
            array_keys($models)
        );

        $new = $toItems(array_diff_key($official, $stored));
        $missing = $toItems(array_diff_key($stored, $official));
        foreach ($new as $index => $item) {
            $new[$index]['change'] = 'new';
        }
        foreach ($missing as $index => $item) {
            $missing[$index]['change'] = 'missing';
        }

        return ['new' => $new, 'missing' => $missing];
    }

    /**
     * 应用管理端忽略清单（VerifyCodingPlanRatios::IGNORE_KEYS_OPTION，键 = vendor|kind|model|match_type）
     * 并固化变更键。返回 [标记后的 changes, 待确认数, pendingKeys]。
     *
     * @param  array<string, list<array<string, mixed>>>  $changes
     * @return array{0: array<string, list<array<string, mixed>>>, 1: int, 2: list<string>}
     */
    public function markIgnoredChanges(string $vendor, array $changes): array
    {
        // Option::get 对 JSON 值自动 decode → 可能返回 array 或 JSON 字符串，双态兼容
        $ignoreRaw = OptionService::get(VerifyCodingPlanRatios::IGNORE_KEYS_OPTION, '[]');
        $ignoreKeys = is_array($ignoreRaw) ? $ignoreRaw : (json_decode((string) $ignoreRaw, true) ?: []);
        $ignoreSet = is_array($ignoreKeys) ? array_flip($ignoreKeys) : [];

        $pendingKeys = [];
        $pendingCount = 0;
        foreach (['new', 'changed', 'missing', 'model_catalog'] as $kind) {
            foreach (($changes[$kind] ?? []) as $index => $item) {
                $key = $kind.'|'.($item['model'] ?? '').'|'.($item['match_type'] ?? '');
                $pendingKeys[] = $key;
                if (isset($ignoreSet[$vendor.'|'.$key])) {
                    $changes[$kind][$index]['ignored'] = true;

                    continue;
                }
                $changes[$kind][$index]['key'] = $key;
                $pendingCount++;
            }
        }

        return [$changes, $pendingCount, $pendingKeys];
    }

    /**
     * 写入校对流水（结构/语义与 verify-ratios 一致；stale 检测归 verify，这里固定 0）。
     *
     * @param  array<string, list<array<string, mixed>>>|null  $changes
     * @param  list<string>|null  $pendingKeys
     */
    public function recordCheck(string $vendor, int $status, ?array $changes = null, ?array $pendingKeys = null, ?int $changeCount = null): void
    {
        CodingPlanRatioCheck::query()->create([
            'vendor' => $vendor,
            'checked_at' => time(),
            'stale_count' => 0,
            'change_count' => $changeCount ?? 0,
            'source_status' => $status,
            'changes' => ($changes === null || $changes === []) ? null : json_encode($changes, JSON_UNESCAPED_UNICODE),
            'pending_keys' => ($pendingKeys === null || $pendingKeys === []) ? null : json_encode($pendingKeys, JSON_UNESCAPED_UNICODE),
            'created_at' => time(),
        ]);
    }
}
