<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\CodingPlanModelRatio;
use App\Models\CodingPlanRatioCheck;
use App\Models\CodingPlanVendor;
use App\Services\CodingPlanOfficialSourceService;
use App\Services\CodingPlanParsers\CodingPlanParserInterface;
use App\Services\OptionService;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Schema;

/**
 * Coding Plan 官方源同步（每 6 小时调度，先于 verify-ratios；可手动执行）
 *
 * 与 VerifyCodingPlanRatios 的分工：
 *  - verify-ratios：stale 检测 + 管理端 pricing_source_url（结构化 JSON）diff；
 *  - 本命令（P1-1/P1-4/P1-7）：按内置源注册表抓取官方 HTML/Markdown 定价页与
 *    模型目录页，解析为与结构化源同一约定的条目，落快照（每厂商保留 10 份），
 *    并与库内比率/目录 diff → 写入校对流水（new/changed/missing/model_catalog）。
 *
 * 铁律同 verify：只记录、不自动改价 —— 应用/忽略走管理端「官方同步」确认闭环
 * （P1-6/P3-2 界面；忽略键复用 CodingPlanRatioIgnoreKeys）。
 *
 * 代理：境外源（openai/google/anthropic/xai）需 PEASE_API_HTTP_PROXY（P1-8），
 * 国内源直连；代理未配置时境外源抓取失败会记 source_failed 流水。
 *
 * 快照文件：storage/app/coding-plan-snapshots/{vendor}/{Y-m-d-Hi}.json
 * （解析失败另存 .raw.txt 原始响应，供适配器调试；P1-4）。
 */
class SyncCodingPlanOfficial extends Command
{
    protected $signature = 'coding-plan:sync-official
        {--vendor= : 点名同步指定厂商（逗号分隔 code，强制纳入即使停用；缺省=全范围）}
        {--snapshot-only : 仅抓取并保存快照，不做 diff / 不写校对流水}';

    protected $description = 'Fetch official coding plan pricing/catalog pages, archive snapshots, and diff ratios & model catalog (changes recorded, never auto-applied)';

    public function handle(CodingPlanOfficialSourceService $sources): int
    {
        foreach (['coding_plan_vendors', 'coding_plan_model_ratios', 'coding_plan_ratio_checks'] as $table) {
            if (! Schema::hasTable($table)) {
                $this->info("Table {$table} missing (upgrade pending) - skipping official source sync.");

                return self::SUCCESS;
            }
        }

        if (! (bool) OptionService::get('CodingPlanRatioVerifyEnabled', true)) {
            $this->info('CodingPlanRatioVerifyEnabled=false - official source sync disabled.');

            return self::SUCCESS;
        }

        // 同步范围 = 启用供应商 ∪ 比率表现存厂商（含停用价目厂商，与 verify-ratios 同口径）
        $codes = CodingPlanVendor::query()->where('status', 1)->orderBy('sort')->orderBy('id')
            ->pluck('code')
            ->merge(CodingPlanModelRatio::query()->distinct()->pluck('vendor'))
            ->unique()
            ->values();
        $only = array_filter(array_map('trim', explode(',', (string) $this->option('vendor'))));
        if ($only !== []) {
            // 点名同步：缩小到指定厂商（缺省全范围）；停用厂商强制纳入（手动补抓价目）
            $codes = $codes->intersect($only)->merge(collect($only))->unique()->values();
        }

        $synced = 0;
        $pendingTotal = 0;
        $failures = 0;
        foreach ($codes as $code) {
            $result = $this->syncVendor($sources, (string) $code);
            if ($result < 0) {
                $failures++;
            } else {
                $synced++;
                $pendingTotal += $result;
            }
        }

        $this->info(sprintf(
            'Synced %d/%d vendors: %d pending change(s), %d failure(s). Snapshots: %d per vendor retained.',
            $synced,
            $codes->count(),
            $pendingTotal,
            $failures,
            CodingPlanOfficialSourceService::SNAPSHOT_KEEP
        ));

        return self::SUCCESS;
    }

    /**
     * 单厂商同步：抓取 → 解析 → 快照 →（可选）diff → 流水。
     * 返回：-1 失败 / 0 无变更或跳过 / >0 待确认变更数。
     */
    protected function syncVendor(CodingPlanOfficialSourceService $sources, string $code): int
    {
        /** @var CodingPlanVendor|null $vendor */
        $vendor = CodingPlanVendor::query()->where('code', $code)->first();
        $label = $vendor?->name ?? $code;

        $source = $sources->resolveSource($vendor?->pricing_source_url, $code);
        if ($source === null) {
            $this->line("[{$code}] {$label}: 无官方源（内置注册表与管理端 pricing_source_url 均未配置）");

            return 0;
        }

        // 解析器未就绪：仅存原始快照（P1-2 适配器待补）
        if ($source['kind'] === 'builtin' && $source['parser'] === null) {
            $body = $sources->fetchBody($source['url'], $source['proxy']);
            if ($body === null) {
                $this->line("[{$code}] {$label}: 抓取失败（{$source['url']}）");

                return -1;
            }
            $path = $sources->saveSnapshot($code, $this->snapshotPayload($source, $code, false, 0, 0), $body);
            $this->line("[{$code}] {$label}: 解析器待实现（{$source['format']}），原始快照 → {$path}");

            return 0;
        }

        $body = $sources->fetchBody($source['url'], $source['proxy'], $source['kind'] === 'structured');
        if ($body === null) {
            // 抓取失败：沿用上次快照的决策不受影响，只记 source_failed 流水
            // （同一源连续 ≥2 次升级 SOURCE_FAILED_ALERT，P1-9 管理端红点）
            $failedStatus = $sources->resolveSourceStatus($code, CodingPlanRatioCheck::SOURCE_FAILED);
            $sources->recordCheck($code, $failedStatus);
            $this->line("[{$code}] {$label}: 抓取失败（{$source['url']}），已记 ".($failedStatus === CodingPlanRatioCheck::SOURCE_FAILED_ALERT ? 'source_failed_alert（连续失败告警）' : 'source_failed'));

            return -1;
        }

        // 条目解析：结构化 JSON 与内置解析器输出同一约定
        $parser = null;
        if ($source['kind'] === 'structured') {
            $entries = $sources->normalizeStructuredEntries(json_decode($body, true));
        } else {
            /** @var CodingPlanParserInterface $parser */
            $parser = app($source['parser']);
            $entries = $parser->parsePricing($body);
        }

        // 模型目录：独立 model_catalog_url，或 catalog_from_pricing（目录与定价同一响应体）。
        // 目录产出先于条目判空 —— 套餐积分类页面（如 zhipu）无定价条目但有模型清单
        $catalog = [];
        $catalogBody = null;
        if ($source['model_catalog_url'] !== null) {
            $catalogBody = $sources->fetchBody($source['model_catalog_url'], $source['proxy']);
        } elseif (($source['catalog_from_pricing'] ?? false) && $parser !== null) {
            $catalogBody = $body;
        }
        if ($catalogBody !== null && $parser !== null) {
            $catalog = $parser->parseCatalog($catalogBody);
        }

        if ($entries === [] && $catalog === []) {
            $path = $sources->saveSnapshot($code, $this->snapshotPayload($source, $code, false, 0, 0), $body);
            $sources->recordCheck($code, CodingPlanRatioCheck::SOURCE_FAILED);
            $this->line("[{$code}] {$label}: 解析出 0 条条目（原始响应已存快照 → {$path}）");

            return -1;
        }

        $path = $sources->saveSnapshot(
            $code,
            array_merge($this->snapshotPayload($source, $code, true, count($entries), count($catalog)), [
                'entries' => $entries,
                'catalog' => $catalog,
            ])
        );

        if ((bool) $this->option('snapshot-only')) {
            $catalogText = $catalog !== [] ? '，'.count($catalog).' 个目录模型' : '';
            $this->line("[{$code}] {$label}: 快照已存（".count($entries)." 条定价{$catalogText}）→ {$path}");

            return 0;
        }

        /** @var Collection<int, CodingPlanModelRatio> $ratios */
        // 全量行（含停用）：存在性比对不受上下架影响；数值 diff 在 diffEntries 内只对启用行生效
        $ratios = CodingPlanModelRatio::query()->where('vendor', $code)->get();
        $changes = $sources->diffEntries($entries, $ratios);

        if ($catalog !== []) {
            $catalogDiff = $sources->diffCatalogModels($catalog, $ratios);
            $catalogChanges = array_merge($catalogDiff['new'], $catalogDiff['missing']);
            if ($catalogChanges !== []) {
                $changes['model_catalog'] = $catalogChanges;
            }
        }

        [$changes, $pendingCount, $pendingKeys] = $sources->markIgnoredChanges($code, $changes);
        $sources->recordCheck($code, CodingPlanRatioCheck::SOURCE_OK, $changes, $pendingKeys, $pendingCount);

        // 校对结果影响公开介绍页/offers 聚合缓存
        if ($changes !== []) {
            Cache::forget('coding_plan_offers');
        }

        $buckets = ['new' => '新增', 'changed' => '变更', 'missing' => '下架', 'model_catalog' => '目录'];
        $summary = $changes === []
            ? '无变更'
            : collect($buckets)
                ->filter(fn (string $text, string $kind): bool => isset($changes[$kind]) && $changes[$kind] !== [])
                ->map(fn (string $text, string $kind): string => $text.' '.count($changes[$kind]))
                ->implode('，');
        $detail = $pendingCount > 0 ? "发现 {$pendingCount} 处待确认变更（{$summary}）" : $summary;
        $this->line("[{$code}] {$label}: 源同步 {$detail}，快照 → {$path}");

        return $pendingCount;
    }

    /**
     * 快照 payload 公共字段。
     *
     * @param  array{url: string, kind: string, format: string, parser: ?string, proxy: bool, model_catalog_url: ?string, notes: string}  $source
     * @return array<string, mixed>
     */
    protected function snapshotPayload(array $source, string $code, bool $parsed, int $entryCount, int $catalogCount): array
    {
        $proxyConfigured = trim((string) env(CodingPlanOfficialSourceService::PROXY_ENV, '')) !== '';

        return [
            'vendor' => $code,
            'kind' => $source['kind'],
            'format' => $source['format'],
            'url' => $source['url'],
            'proxy_used' => $source['proxy'] && $proxyConfigured,
            'fetched_at' => time(),
            'parser' => $source['parser'],
            'parsed' => $parsed,
            'entry_count' => $entryCount,
            'catalog_count' => $catalogCount,
            'notes' => $source['notes'],
        ];
    }
}
