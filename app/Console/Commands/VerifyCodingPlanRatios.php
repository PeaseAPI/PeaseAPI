<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\CodingPlanModelRatio;
use App\Models\CodingPlanRatioCheck;
use App\Models\CodingPlanVendor;
use App\Services\OptionService;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;

/**
 * Coding Plan 折算比率校对（每 6 小时调度，可手动执行）
 *
 * 背景：供应商（火山引擎等）常在新增模型时推出优惠活动，管理员手工维护的
 * 折算比率（coding_plan_model_ratios.unit_cost / unit_exchange_rate）可能过时。
 *
 * 本命令做两件事（只记录、不自动改价 —— 改错比率等于资损，变更须人工确认）：
 *  1. stale 检测：启用比率超过 CodingPlanRatioStaleDays（默认 7 天）未人工复核
 *     → 公开介绍页/管理端标记「待复核」。
 *  2. 定价源 diff：供应商配置了 pricing_source_url（结构化 JSON）时拉取比对，发现
 *     新增模型（new）/ 单位成本或三段系数变化（changed）/ 源中消失即老模型下架（missing）
 *     → 写入校对流水 changes 字段，并把变更键固化到 pending_keys 列，
 *     管理端「官方同步」页据此生成「待客户确认」清单（应用 / 忽略）。
 *
 * 变更确认闭环：管理员在管理端应用某条变更（改价/新增停用行/下架停用）后，
 * 下一次 diff 自然消失；不认可源的变更可「忽略」（键存 CodingPlanRatioIgnoreKeys，
 * 仍展示但标 ignored=true 且不计入 change_count，可随时恢复）。
 * 结果落 coding_plan_ratio_checks，公开 API /api/coding_plan/offers 与
 * 介绍页 /coding-plan 展示每供应商最后核对时间与状态。
 *
 * 定价源约定格式（HTTP 200 + JSON）：
 *   {"models":[{"model":"doubao-lite","unit_cost":0.5,"match_type":"exact"},
 *              {"model":"glm-5.3","cost_mode":"per_token_parts","input_rate":0.69,"cached_rate":0.17,"output_rate":2.4}]}
 *   或直接为数组；match_type 缺省 exact，unit_cost 与三段分段率均为数值的条目才参与比对，
 *   per_token_parts 条目以 input_rate/cached_rate/output_rate 为准（unit_cost 可省略）。
 */
class VerifyCodingPlanRatios extends Command
{
    /** 校对流水的保留天数 */
    protected const CHECK_RETENTION_DAYS = 90;

    /** 忽略清单 option：JSON 数组，元素为 "vendor|kind|model|match_type"（管理端可恢复） */
    public const IGNORE_KEYS_OPTION = 'CodingPlanRatioIgnoreKeys';

    protected $signature = 'coding-plan:verify-ratios
        {--vendor= : 仅校对指定厂商（逗号分隔 code，缺省全部）}';

    protected $description = 'Verify Coding Plan deduction ratios (stale detection + optional pricing source diff for new/changed/retired models; changes recorded, never auto-applied)';

    public function handle(): int
    {
        foreach (['coding_plan_vendors', 'coding_plan_model_ratios', 'coding_plan_ratio_checks'] as $table) {
            if (! Schema::hasTable($table)) {
                $this->info("Table {$table} missing (upgrade pending) - skipping ratio verification.");

                return self::SUCCESS;
            }
        }

        if (! (bool) OptionService::get('CodingPlanRatioVerifyEnabled', true)) {
            $this->info('CodingPlanRatioVerifyEnabled=false - ratio verification disabled.');

            return self::SUCCESS;
        }

        $now = time();
        $staleDays = max(1, (int) OptionService::get('CodingPlanRatioStaleDays', 7));
        $staleBefore = $now - $staleDays * 86400;

        // 校对范围 = 启用供应商 ∪ 比率表现存厂商（比率表的孤儿厂商同样被校对）；
        // --vendor= 可缩小到指定厂商（手动核对单家时避免整轮跑）
        $codes = CodingPlanVendor::query()->where('status', 1)->orderBy('sort')->orderBy('id')
            ->pluck('code')
            ->merge(CodingPlanModelRatio::query()->where('status', 1)->distinct()->pluck('vendor'))
            ->unique()
            ->values();
        $only = array_filter(array_map('trim', explode(',', (string) $this->option('vendor'))));
        if ($only !== []) {
            // 点名校对：强制纳入（即使该厂商未启用且无启用比率，也生成校对流水）
            $codes = $codes->merge(collect($only))->unique()->values();
        }

        $vendors = CodingPlanVendor::query()->whereIn('code', $codes)->get()->keyBy('code');

        // 忽略清单：管理员不认可源的变更（仍展示但标 ignored，不计入 change_count）
        // Option::get 对 JSON 值自动 decode → 可能返回 array 或 JSON 字符串，双态兼容
        $ignoreRaw = OptionService::get(self::IGNORE_KEYS_OPTION, '[]');
        $ignoreKeys = is_array($ignoreRaw) ? $ignoreRaw : (json_decode((string) $ignoreRaw, true) ?: []);
        $ignoreSet = is_array($ignoreKeys) ? array_flip($ignoreKeys) : [];

        $totalStale = 0;
        $totalChanges = 0;
        $sourceFailures = 0;

        foreach ($codes as $code) {
            /** @var CodingPlanVendor|null $vendor */
            $vendor = $vendors->get($code);

            /** @var Collection<int, CodingPlanModelRatio> $ratios */
            $ratios = CodingPlanModelRatio::query()
                ->where('vendor', $code)
                ->where('status', 1)
                ->get();

            $staleCount = $ratios->filter(
                fn (CodingPlanModelRatio $r) => $r->updated_at > 0 && $r->updated_at < $staleBefore
            )->count();

            [$sourceStatus, $changes] = $this->diffPricingSource($vendor?->pricing_source_url, $ratios);

            // 打忽略标记并固化变更键（kind|model|match_type，供管理端确认清单与忽略恢复）
            $pendingKeys = [];
            $pendingCount = 0;
            foreach (['new', 'changed', 'missing'] as $kind) {
                foreach (($changes[$kind] ?? []) as $index => $item) {
                    $key = $kind.'|'.($item['model'] ?? '').'|'.($item['match_type'] ?? '');
                    $pendingKeys[] = $key;
                    if (isset($ignoreSet[$code.'|'.$key])) {
                        $changes[$kind][$index]['ignored'] = true;

                        continue;
                    }
                    $changes[$kind][$index]['key'] = $key;
                    $pendingCount++;
                }
            }

            CodingPlanRatioCheck::query()->create([
                'vendor' => $code,
                'checked_at' => $now,
                'stale_count' => $staleCount,
                'change_count' => $pendingCount,
                'source_status' => $sourceStatus,
                'changes' => $changes === [] ? null : json_encode($changes, JSON_UNESCAPED_UNICODE),
                'pending_keys' => $pendingKeys === [] ? null : json_encode($pendingKeys, JSON_UNESCAPED_UNICODE),
                'created_at' => $now,
            ]);

            $totalStale += $staleCount;
            $totalChanges += $pendingCount;
            if ($sourceStatus === CodingPlanRatioCheck::SOURCE_FAILED) {
                $sourceFailures++;
            }

            $label = $vendor?->name ?? $code;
            $sourceText = match ($sourceStatus) {
                CodingPlanRatioCheck::SOURCE_OK => '源比对 '.($pendingCount > 0 ? "发现 {$pendingCount} 处待确认变更" : '无变更'),
                CodingPlanRatioCheck::SOURCE_FAILED => '源拉取失败',
                default => '未配置定价源',
            };
            $staleText = $staleCount > 0 ? "，{$staleCount} 条待复核" : '';
            $this->line("[{$code}] {$label}: {$sourceText}{$staleText}");
        }

        $pruned = CodingPlanRatioCheck::query()
            ->where('checked_at', '<', $now - self::CHECK_RETENTION_DAYS * 86400)
            ->delete();

        // 校对结果影响公开介绍页/offers 聚合缓存
        Cache::forget('coding_plan_offers');

        $this->info("Verified {$codes->count()} vendors: {$totalStale} stale ratio(s), {$totalChanges} source change(s), {$sourceFailures} source failure(s).");

        if ($pruned > 0) {
            $this->info("Pruned {$pruned} check record(s) older than ".self::CHECK_RETENTION_DAYS.' days.');
        }

        return self::SUCCESS;
    }

    /**
     * 与结构化定价源 diff（只报告，不落库到比率表）。
     *
     * @param  Collection<int, CodingPlanModelRatio>  $ratios
     * @return array{0: int, 1: array<string, list<array<string, mixed>>>}
     */
    protected function diffPricingSource(?string $url, Collection $ratios): array
    {
        if ($url === null || $url === '') {
            return [CodingPlanRatioCheck::SOURCE_NONE, []];
        }

        try {
            $response = Http::timeout(10)->connectTimeout(5)->acceptJson()->get($url);
        } catch (\Throwable) {
            return [CodingPlanRatioCheck::SOURCE_FAILED, []];
        }

        if (! $response->successful()) {
            return [CodingPlanRatioCheck::SOURCE_FAILED, []];
        }

        $payload = $response->json();
        // 接受 {"models":[...]} 包裹或直接数组两种形态
        $entries = is_array($payload)
            ? (array_key_exists('models', $payload) && is_array($payload['models']) ? $payload['models'] : $payload)
            : null;
        if (! is_array($entries)) {
            return [CodingPlanRatioCheck::SOURCE_FAILED, []];
        }

        // 规范化源条目：key = model|match_type
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
            ];
        }

        if ($source === []) {
            // 拉到了但解析不出任何条目，视为源异常
            return [CodingPlanRatioCheck::SOURCE_FAILED, []];
        }

        /** @var Collection<string, CodingPlanModelRatio> $current */
        $current = $ratios->keyBy(fn (CodingPlanModelRatio $r) => $r->model.'|'.$r->match_type);

        $new = [];
        $changed = [];
        foreach ($source as $key => $item) {
            $existing = $current->get($key);
            if ($existing === null) {
                $new[] = $item;

                continue;
            }
            // 分段口径：比对三段系数；其余口径：比对 unit_cost（容差 0.0001）
            if ($item['cost_mode'] === CodingPlanModelRatio::COST_PER_TOKEN_PARTS) {
                $diff = max(
                    abs((float) $existing->input_rate - $item['input_rate']),
                    abs((float) $existing->cached_rate - $item['cached_rate']),
                    abs((float) $existing->output_rate - $item['output_rate'])
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
            if (abs((float) $existing->unit_cost - $item['unit_cost']) >= 0.0001) {
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
                if ($ratio->match_type === CodingPlanModelRatio::MATCH_EXACT && ! isset($source[$key])) {
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

        return [CodingPlanRatioCheck::SOURCE_OK, $changes];
    }
}
