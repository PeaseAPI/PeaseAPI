<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * P3-4 移动 Token Plan 个人版视觉/视频模型豆率预置（2026-09-13 官方补全，ART 100224）
 *
 * 官方「支持模型及抵扣关系」新增三张表（此前仅文本 13 档，000008 已入库 11 行）：
 *  1. 图片：Qwen/Qwen-image-2.0-pro 1 豆=0.03 张 → 33.3333 豆/张；
 *  2. 视频（1 豆=N 秒，按分辨率分档）：happyhorse-1.0-video-edit / 1.1-i2v / 1.1-r2v /
 *     1.1-t2v，720P 均为 1:0.015（66.6667 豆/秒）；1080P video-edit 1:0.009（111.1111）、
 *     其余三款 1:0.012（83.3333）；
 *  3. 文本分档化：Qwen3.7-plus 0-256K=6500（原单档值）/256K-1M=2300；Minimax-m3
 *     0-512K=9000（原单档值）/512K-1M=4500。
 *
 * 口径决策：
 *  - cost_mode 扩枚举 per_image（豆/张）/ per_video_second（豆/秒）——unit_cost 语义随
 *    cost_mode 走，与 per_1k_tokens（豆/千 tokens）互不混淆；
 *  - 视频 1080P 以官方原文形态落独立 exact 行（模型名带（1080P）后缀，真实调用模型 id
 *    无括号不会误命中）；官方预扣=按最高清晰度汇率冻结 10 秒、多退少补，计费联动待渠道
 *    层捕获张/秒 usage（calcUsage 预置期按次保守兜底）；
 *  - 文本分档差异留痕于 remark（分段计费能力未接入，维持首档 exact 行保守折算，
 *    分档值换算：2300→0.4348 豆/千、4500→0.2222 豆/千）；
 *  - 全部行 status=0 预置态，管理员核对后启用。
 */
return new class extends Migration
{
    /** 官方「单个算力豆兑换图片张数/视频秒数」→ unit_cost（豆/张、豆/秒） */
    public const VISUAL_ROWS = [
        // 图片
        ['Qwen/Qwen-image-2.0-pro', 'per_image', 33.3333, '1 豆=0.03 张（官方 2026-09-13）'],
        // 视频 720P（首档）
        ['Qwen/happyhorse-1.0-video-edit', 'per_video_second', 66.6667, '1 豆=0.015 秒（720P；官方 2026-09-13）'],
        ['Qwen/happyhorse-1.1-i2v', 'per_video_second', 66.6667, '1 豆=0.015 秒（720P；官方 2026-09-13）'],
        ['Qwen/happyhorse-1.1-r2v', 'per_video_second', 66.6667, '1 豆=0.015 秒（720P；官方 2026-09-13）'],
        ['Qwen/happyhorse-1.1-t2v', 'per_video_second', 66.6667, '1 豆=0.015 秒（720P；官方 2026-09-13）'],
        // 视频 1080P（官方原文（1080P）后缀形态，真实调用 id 无括号不误命中）
        ['Qwen/happyhorse-1.0-video-edit（1080P）', 'per_video_second', 111.1111, '1 豆=0.009 秒（1080P）；官方预扣=最高清晰度汇率 10 秒冻结、多退少补'],
        ['Qwen/happyhorse-1.1-i2v（1080P）', 'per_video_second', 83.3333, '1 豆=0.012 秒（1080P）；官方预扣=最高清晰度汇率 10 秒冻结、多退少补'],
        ['Qwen/happyhorse-1.1-r2v（1080P）', 'per_video_second', 83.3333, '1 豆=0.012 秒（1080P）；官方预扣=最高清晰度汇率 10 秒冻结、多退少补'],
        ['Qwen/happyhorse-1.1-t2v（1080P）', 'per_video_second', 83.3333, '1 豆=0.012 秒（1080P）；官方预扣=最高清晰度汇率 10 秒冻结、多退少补'],
    ];

    /** 文本分档留痕（官方 2026-09-13 起分长上下文档；行值维持首档，分段能力接入前保守折算） */
    public const TIERED_NOTES = [
        'Qwen/Qwen3.7-plus' => '256K-1M 档 1:2300（0.4348 豆/千）',
        'Minimax/Minimax-m3' => '512K-1M 档 1:4500（0.2222 豆/千）',
    ];

    public function up(): void
    {
        if (! Schema::hasTable('coding_plan_model_ratios')) {
            return;
        }

        $now = time();
        foreach (self::VISUAL_ROWS as [$model, $costMode, $unitCost, $note]) {
            $exists = DB::table('coding_plan_model_ratios')
                ->where('vendor', 'cmcc-token')
                ->where('model', $model)
                ->exists();
            if ($exists) {
                continue;
            }
            DB::table('coding_plan_model_ratios')->insert([
                'vendor' => 'cmcc-token',
                'model' => $model,
                'match_type' => 'exact',
                'cost_mode' => $costMode,
                'unit_cost' => $unitCost,
                'input_rate' => 0,
                'cached_rate' => 0,
                'output_rate' => 0,
                'time_discounts' => null,
                'status' => 0,
                'sort' => 100,
                'remark' => '官方折算标准（2026-09-13 视觉/视频补全）：'.$note,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }

        // 文本分档留痕：仅修正预置态行，不触碰管理员维护的数据
        foreach (self::TIERED_NOTES as $model => $tierNote) {
            DB::table('coding_plan_model_ratios')
                ->where('vendor', 'cmcc-token')
                ->where('model', $model)
                ->where('status', 0)
                ->where('remark', 'like', '官方折算标准%')
                ->update([
                    'remark' => DB::raw("CONCAT(remark, '；官方 2026-09-13 起分长上下文档：".$tierNote."，分段计费能力接入前按首档保守折算')"),
                    'updated_at' => $now,
                ]);
        }
    }

    public function down(): void
    {
        if (! Schema::hasTable('coding_plan_model_ratios')) {
            return;
        }

        // 视觉/视频 9 行（本轮新增，特征=cost_mode 新枚举 + 预置 remark）
        DB::table('coding_plan_model_ratios')
            ->where('vendor', 'cmcc-token')
            ->whereIn('cost_mode', ['per_image', 'per_video_second'])
            ->where('remark', 'like', '官方折算标准%')
            ->delete();

        // 文本分档留痕还原
        foreach (self::TIERED_NOTES as $model => $tierNote) {
            DB::table('coding_plan_model_ratios')
                ->where('vendor', 'cmcc-token')
                ->where('model', $model)
                ->where('status', 0)
                ->where('remark', 'like', '%官方 2026-09-13 起分长上下文档：'.$tierNote.'%')
                ->update([
                    'remark' => DB::raw("REPLACE(remark, '；官方 2026-09-13 起分长上下文档：".$tierNote."，分段计费能力接入前按首档保守折算', '')"),
                    'updated_at' => time(),
                ]);
        }
    }
};
