<?php

declare(strict_types=1);

use App\Services\CodingPlanCatalog;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Coding Plan 移动官方价目补全（2026-09-11 经 CMS API 逆向抓取官方帮助中心文档核对）
 *
 * 关键突破：移动云帮助中心（ecloud.10086.cn/op-help-center）为纯前端 SPA（HTML 壳 1KB），
 * 此前误判为 WAF 拒绝程序化访问；实为 CMS 数据接口未暴露——从 app.ec96365a.js 逆向出
 * GET /op-help-center/request-api/service-api/article/content/{文件UID}
 * （article/info/{id} 取 UID；头 categoryRootParent/tentId/isPreview）后全量文档可抓。
 *  1. Coding Plan（ART 98320 介绍、98337 QA、98322 接入）：Lite/Pro 40/200 元/月
 *     → 18,000/90,000 次请求（5 小时/周/订阅月三重限额），仅 MiniMax-M2.5（系数 1，192K），
 *     每请求扣 1 次（cm-code-latest Auto 路由同价）；首订活动价 7.9/39.9 元（至 2026-12-31）。
 *  2. Token Plan 个人版（ART 100224）：「算力豆」计量，月包 5~500 元 7 档 + 次包 3 档
 *     + 尝鲜包 9.9 元，1 豆按模型兑换率折算 tokens（GLM-5.1 1500 / V4-Flash 11000 / Auto 10000 等），
 *     比率行存豆/千 token = 1000÷兑换率（全量 token 统一折算，不分输入/输出/缓存）。
 *  3. Token Plan 团队版（ART 99471）：「折算 tokens」倍率计量（消耗 M 扣 M×N），
 *     Lite/团队版 1,000/5,000 元 → 10 亿/55 亿折算 tokens；12 个模型系数全量入库（unit_cost=N）。
 *     团队版（折算 tokens）与个人版（算力豆）计量口径不同，拆独立厂商 cmcc-token-team 防汇率混用。
 *  4. 通过 CodingPlanCatalog（单一事实源）幂等落地：档位公开态，比率行全部停用（管理员核对后启用）。
 */
return new class extends Migration
{
    /** 仅修正仍为预置态的厂商行（status=0 且 remark 为预置/模板落地），不触碰管理员维护的数据 */
    public const VENDOR_FIXES = [
        'cmcc' => ['unit_name' => '次请求', 'docs_url' => 'https://ecloud.10086.cn/op-help-center/doc/article/98320'],
        'cmcc-token' => ['name' => '中国移动 Token Plan 个人版', 'unit_name' => '算力豆', 'docs_url' => 'https://ecloud.10086.cn/op-help-center/doc/article/100224'],
    ];

    /** 本次涉及的模板（apply 幂等：cmcc-token-team 新建厂商，cmcc/cmcc-token 增补档位与比率） */
    public const APPLY_TEMPLATES = ['cmcc', 'cmcc-token', 'cmcc-token-team'];

    public function up(): void
    {
        if (! Schema::hasTable('coding_plan_vendors')) {
            return;
        }

        $now = time();
        foreach (self::VENDOR_FIXES as $code => $fields) {
            DB::table('coding_plan_vendors')
                ->where('code', $code)
                ->where('status', 0)
                ->where(function ($q) {
                    $q->where('remark', 'like', '预置厂商%')->orWhere('remark', 'like', '官方模板落地%');
                })
                ->update($fields + ['updated_at' => $now]);
        }

        foreach (self::APPLY_TEMPLATES as $code) {
            // apply() 内部会自动新建缺失的厂商（如本轮 cmcc-token-team），不做 exists 跳过
            CodingPlanCatalog::apply($code, $now);
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('coding_plan_model_ratios')) {
            // Coding Plan：MiniMax-M2.5 / cm-code-latest 两条 per_request
            DB::table('coding_plan_model_ratios')
                ->where('vendor', 'cmcc')
                ->whereIn('model', ['MiniMax-M2.5', 'cm-code-latest'])
                ->where('remark', 'like', '官方折算标准%')
                ->delete();
            // Token Plan 个人版：11 条算力豆兑换率
            DB::table('coding_plan_model_ratios')
                ->where('vendor', 'cmcc-token')
                ->where('remark', 'like', '官方折算标准%')
                ->delete();
            // Token Plan 团队版：12 条折算系数（本轮新建厂商，整体回滚）
            DB::table('coding_plan_model_ratios')
                ->where('vendor', 'cmcc-token-team')
                ->where('remark', 'like', '官方折算标准%')
                ->delete();
        }

        if (Schema::hasTable('coding_plan_vendor_tiers')) {
            // cmcc-token-team 为本轮新建厂商，档位一并回滚
            DB::table('coding_plan_vendor_tiers')
                ->where('vendor_code', 'cmcc-token-team')
                ->where('remark', 'like', '官方档位%')
                ->delete();
            // cmcc / cmcc-token 档位回滚
            DB::table('coding_plan_vendor_tiers')
                ->whereIn('vendor_code', ['cmcc', 'cmcc-token'])
                ->where('remark', 'like', '官方档位%')
                ->delete();
        }

        if (Schema::hasTable('coding_plan_vendors')) {
            // cmcc-token-team 厂商整体回滚（仅官方预置态）
            DB::table('coding_plan_vendors')
                ->where('code', 'cmcc-token-team')
                ->where('status', 0)
                ->where('remark', 'like', '官方模板落地%')
                ->delete();
            // cmcc / cmcc-token 厂商字段还原为壳模板口径
            DB::table('coding_plan_vendors')
                ->where('code', 'cmcc')
                ->where('status', 0)
                ->where('remark', 'like', '预置厂商%')
                ->update([
                    'unit_name' => '点',
                    'docs_url' => 'https://ecloud.10086.cn/op-help-center/doc/article/98322',
                    'updated_at' => time(),
                ]);
            DB::table('coding_plan_vendors')
                ->where('code', 'cmcc-token')
                ->where('status', 0)
                ->where('remark', 'like', '预置厂商%')
                ->update([
                    'name' => '中国移动 Token Plan',
                    'unit_name' => '千token',
                    'docs_url' => 'https://ecloud.10086.cn/op-help-center/doc/outline/108724',
                    'updated_at' => time(),
                ]);
        }
    }
};
