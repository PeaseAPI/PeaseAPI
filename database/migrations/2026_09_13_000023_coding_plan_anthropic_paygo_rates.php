<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * P3-6b anthropic 官方按量价目预置（阻塞解除，2026-09-13）：
 *
 * 源演变：docs.anthropic.com 301 → platform.claude.com（域名迁移）；直连被区域封锁
 * （301 → claude.com/app-unavailable-in-region），此前「代理下仍拦正文」疑为代理出口
 * 被识别——本地代理（PEASE_API_HTTP_PROXY）出口 200/45KB，.md 官方 Markdown 出口直取。
 * AnthropicParser 同步从 HTML 版（Next.js SSR div 表格）改 Markdown 版（基类
 * markdownTables），retired 行拒收（P1-6 目录口径同步收紧 17 → 13 在售）。
 *
 * 预置 13 行 = 官方 Model pricing 主表在售全集（快照 2026-09-13-1422 镜像，与 sync
 * diff 产出一致）：Fable/Mythos 5.1 与 5、Opus 5/4.8/4.7/4.6/4.5、Sonnet 5/4.6/4.5、
 * Haiku 4.5。5m/1h cache writes（写入价）官方无标准化字段未预置；cached_rate=Cache
 * hits and refreshes 列。库内既有 claude- prefix 兜底行（per_request）不受影响。
 *
 * 幂等：比率行 vendor+model+cost_mode 存在跳过（绝不覆盖人工行）。
 * down 用 remark 前缀识别删除（只删本迁移预置行）。
 */

return new class extends Migration
{
    /** 预置 remark 前缀（down 识别用） */
    public const REMARK_PREFIX = '官方价目参照预置（快照 2026-09-13，platform.claude.com/docs/en/about-claude/pricing.md 官方页，官方 USD/百万 tokens ÷1000 存 /1k，启用前请核对）';

    /** 预置比率：[model, input_rate, cached_rate, output_rate, remark_extra] */
    public const PRESET_RATIOS = [
        ['claude-fable-5.1', 0.01, 0.00025, 0.05, '；Fable 5.1 旗舰档'],
        ['claude-mythos-5.1', 0.01, 0.00025, 0.05, '；Mythos 5.1（官方标注 limited availability）'],
        ['claude-fable-5', 0.01, 0.001, 0.05, ''],
        ['claude-mythos-5', 0.01, 0.001, 0.05, '；Mythos 5（官方标注 limited availability）'],
        ['claude-opus-5', 0.005, 0.0005, 0.025, ''],
        ['claude-opus-4.8', 0.005, 0.0005, 0.025, ''],
        ['claude-opus-4.7', 0.005, 0.0005, 0.025, ''],
        ['claude-opus-4.6', 0.005, 0.0005, 0.025, ''],
        ['claude-opus-4.5', 0.005, 0.0005, 0.025, ''],
        ['claude-sonnet-5', 0.002, 0.0002, 0.01, ''],
        ['claude-sonnet-4.6', 0.003, 0.0003, 0.015, ''],
        ['claude-sonnet-4.5', 0.003, 0.0003, 0.015, ''],
        ['claude-haiku-4.5', 0.001, 0.0001, 0.005, ''],
    ];

    public function up(): void
    {
        if (! Schema::hasTable('coding_plan_model_ratios')) {
            return;
        }

        $now = time();
        foreach (self::PRESET_RATIOS as [$model, $input, $cached, $output, $extra]) {
            $exists = DB::table('coding_plan_model_ratios')->where([
                'vendor' => 'anthropic',
                'model' => $model,
                'cost_mode' => 'per_token_parts',
            ])->exists();
            if ($exists) {
                continue;
            }
            DB::table('coding_plan_model_ratios')->insert([
                'vendor' => 'anthropic',
                'model' => $model,
                'match_type' => 'exact',
                'cost_mode' => 'per_token_parts',
                'unit_cost' => 1,
                'input_rate' => $input,
                'cached_rate' => $cached,
                'output_rate' => $output,
                'time_discounts' => null,
                'status' => 0,
                'sort' => 0,
                'remark' => self::REMARK_PREFIX.$extra,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }
    }

    public function down(): void
    {
        if (! Schema::hasTable('coding_plan_model_ratios')) {
            return;
        }

        DB::table('coding_plan_model_ratios')
            ->where('vendor', 'anthropic')
            ->where('match_type', 'exact')
            ->where('cost_mode', 'per_token_parts')
            ->where('remark', 'like', self::REMARK_PREFIX.'%')
            ->delete();
    }
};
