<?php

declare(strict_types=1);

use App\Services\CodingPlanCatalog;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Coding Plan 腾讯/百度官方价目补全（2026-09-11 经代理直连抓取官方文档核对）
 *
 * 关键突破：腾讯云文档直连返回 200 但为 br 压缩体，此前误判为 WAF 拦截；
 * 加 --compressed 解压后全量文档可抓（product/1823 全目录 200 页）。
 *  1. 腾讯 TokenHub（product/1823/133811《Token Plan 个人版积分用量抵扣规则》、130060《个人版套餐概览》）：
 *     - 通用四档 39/99/299/599 元 → 780/1,980/5,980/11,980 积分；Hy 四档 28/78/238/468 元 → 560/1,560/4,760/9,360 积分；
 *     - 新逻辑模型（与档位无关）三率积分价（积分/百万 tokens）：glm-5.3 160/40/560、glm-5.3-flash 16/4.6/56、
 *       kimi-k3 400/40/2000、kimi-k2.7-code 130/26/540、minimax-m3 42/8.4/168（>512k 翻倍）、hy4-preview 120/6/360；
 *     - Auto（tc-code-latest）与旧逻辑模型（deepseek-v4-*、glm-5/5.1/5.2、minimax-m2.7）按档位统一价
 *       （Lite 22.285/Standard 19.8/Pro 18.687/Max 8.43），模板取 Standard 口径 0.0198 积分/千 token 兜底；
 *     - Hy 系（Hy Token Plan 调用）：hy3/hy3-preview 按档位同价（16/15.6/14.875/14.4）取 Standard 口径，
 *       hy3-202608 落刊例差价 20/80/5（9 月活动价 10/40/2.5：官方示例 Lite 档 18 万输入+1 万输出+82 万缓存
 *       = 4.25 积分，按刊例同用量为 8.5 积分，两组线性验算均吻合）；
 *     - 官方三组线性抵扣示例（hy3、hy3-202608 活动价、minimax-m3 >512k）已逐组验算吻合。
 *  2. 腾讯 Token Plan 企业版（130659 专业套餐）：刊例 5 万积分=500 元/月 → 1 积分=0.01 元（官方明示）；
 *     广州区 20 个 Model ID 积分价全量入库（DeepSeek 系取高峰价，空闲价/新加坡区差异记于模板注释）；
 *     轻享套餐为自定义 Token 池（≥5,000 万 tokens，广州 100 元/月）1:1 抵扣，无逐模型系数，不单独建模板。
 *  3. 百度千帆（doc/qianfan/s/Dmrabu8b6《Token Plan 个人版》，2026-09-10 更新）：
 *     - 双轨同价额度：Token 制 1,000 万/4,200 万/2.3 亿/7 亿 tokens ⇄ 积分制 1,400/6,600/45,000/165,000 积分；
 *     - Token 制 1:1 抵扣（当前生效），deepseek-v4-pro-0813 按 1.8 倍抵扣（仅 Token 制）；
 *     - 积分制「即将支持」，逐模型系数未公布（仅官方示例：V4-Pro 输入 853 tokens≈1 积分）→ 不预置。
 *  4. 通过 CodingPlanCatalog（单一事实源）幂等落地：档位公开态，比率行全部停用（管理员核对后启用）。
 */
return new class extends Migration
{
    /** 仅修正仍为预置态的厂商行（status=0 且 remark 为预置/模板落地），不触碰管理员维护的数据 */
    public const VENDOR_FIXES = [
        'baidu' => ['docs_url' => 'https://cloud.baidu.com/doc/qianfan/s/Dmrabu8b6'],
    ];

    /** 本次涉及的模板（apply 幂等：tencent-team 新建厂商，tencent/baidu 增补比率行） */
    public const APPLY_TEMPLATES = ['tencent', 'tencent-team', 'baidu'];

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

        // 百度旧占位行 deepseek-（000003 预置，unit_cost=1 恰与官方 Token 制 1:1 一致）就地升级为官方预置态，
        // 避免与新官方行撞 vendor+model+match_type 组合而被 apply 跳过
        DB::table('coding_plan_model_ratios')
            ->where('vendor', 'baidu')
            ->where('model', 'deepseek-')
            ->where('match_type', 'prefix')
            ->where('status', 0)
            ->where('remark', 'like', '预置模板%')
            ->update([
                'cost_mode' => 'per_1k_tokens',
                'unit_cost' => 1,
                'remark' => '官方折算标准（模板 v2026-09-11 核对）',
                'updated_at' => $now,
            ]);

        foreach (self::APPLY_TEMPLATES as $code) {
            CodingPlanCatalog::apply($code, $now);
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('coding_plan_model_ratios')) {
            // 腾讯个人版：本轮新增的 14 条比率（6 新逻辑 exact + Auto + Hy 系 3 + 4 prefix 兜底）
            DB::table('coding_plan_model_ratios')
                ->where('vendor', 'tencent')
                ->whereIn('model', ['glm-5.3', 'glm-5.3-flash', 'kimi-k3', 'kimi-k2.7-code', 'minimax-m3', 'hy4-preview', 'tc-code-latest', 'hy3', 'hy3-preview', 'hy3-202608', 'deepseek-', 'glm-', 'minimax-', 'kimi-'])
                ->where('remark', 'like', '官方折算标准%')
                ->delete();
            // 腾讯企业版：20 条广州区积分价
            DB::table('coding_plan_model_ratios')
                ->where('vendor', 'tencent-team')
                ->where('remark', 'like', '官方折算标准%')
                ->delete();
            // 百度：Token 制 1:1 / 1.8x 共 4 条
            DB::table('coding_plan_model_ratios')
                ->where('vendor', 'baidu')
                ->whereIn('model', ['deepseek-v4-pro-0813', 'deepseek-', 'glm-', 'kimi-'])
                ->where('remark', 'like', '官方折算标准%')
                ->delete();
        }

        if (Schema::hasTable('coding_plan_vendor_tiers')) {
            // tencent-team 为本轮新建厂商，档位一并回滚
            DB::table('coding_plan_vendor_tiers')
                ->where('vendor_code', 'tencent-team')
                ->where('remark', 'like', '官方档位%')
                ->delete();
            // 百度四档 quota_note 恢复为本轮之前的文案
            DB::table('coding_plan_vendor_tiers')
                ->where('vendor_code', 'baidu')
                ->where('remark', 'like', '官方档位%')
                ->whereIn('name', ['个人版 · Mini', '个人版 · Lite', '个人版 · Pro', '个人版 · Max'])
                ->update([
                    'quota_note' => DB::raw("CASE name
                        WHEN '个人版 · Mini' THEN '新手尝鲜，7 天限额已取消'
                        WHEN '个人版 · Lite' THEN '日常开发，7 天限额已取消'
                        WHEN '个人版 · Pro' THEN '高频开发'
                        ELSE '重度开发' END"),
                    'updated_at' => time(),
                ]);
        }

        if (Schema::hasTable('coding_plan_vendors')) {
            // tencent-team 厂商整体回滚（仅官方预置态）
            DB::table('coding_plan_vendors')
                ->where('code', 'tencent-team')
                ->where('status', 0)
                ->where('remark', 'like', '官方模板落地%')
                ->delete();
            // 百度 docs_url 恢复为营销页
            DB::table('coding_plan_vendors')
                ->where('code', 'baidu')
                ->where('status', 0)
                ->where('remark', 'like', '预置厂商%')
                ->update(['docs_url' => 'https://cloud.baidu.com/product/codingplan.html', 'updated_at' => time()]);
        }
    }
};
