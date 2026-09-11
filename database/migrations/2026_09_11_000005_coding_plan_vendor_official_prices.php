<?php

declare(strict_types=1);

use App\Services\CodingPlanCatalog;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Coding Plan 官方价目补全（2026-09-11 官方文档全量核对）
 *
 * 1. 预置态厂商元数据修正：volcengine-ark →「火山方舟 Agent Plan / AFP」；
 *    deepseek / moonshot 单位「千token」→「元」（按量计费以人民币结算）；
 *    tencent / baidu 单位「千token」→「积分」（套餐额度以积分计量）。
 * 2. 移除 volcengine 下错位的「Agent Plan · Small」档位（Agent Plan 归属 volcengine-ark）。
 * 3. 通过 CodingPlanCatalog（单一事实源）幂等落地本次补全的官方数据：
 *    - volcengine-ark：Agent Plan 四档官方价（40/200/500/1000 元，2万/10万/25万/50万 AFP）
 *      + 13 条官方 AFP 抵扣系数（2026-09-01 起，比率行停用，管理员核对后启用）；
 *    - volcengine：Coding Plan Lite/Pro 档位（价格以购买页为准，停用态）；
 *    - moonshot：kimi-k3 / k2.7-code(-highspeed) / k2.6 官方三段价目（停用态）；
 *    - baidu：千帆 Token Plan Mini/Lite/Pro/Max 四档官方价（公开展示）。
 */
return new class extends Migration
{
    /** 仅修正仍为预置态的厂商行（status=0 且 remark 为预置/模板落地），不触碰管理员维护的数据 */
    public const VENDOR_FIXES = [
        'volcengine-ark' => ['name' => '火山方舟 Agent Plan', 'unit_name' => 'AFP', 'docs_url' => 'https://www.volcengine.com/docs/82379/2366394'],
        'deepseek' => ['unit_name' => '元', 'docs_url' => 'https://api-docs.deepseek.com/zh-cn/quick_start/pricing/'],
        'moonshot' => ['unit_name' => '元', 'docs_url' => 'https://platform.kimi.com/docs/pricing/chat'],
        'tencent' => ['unit_name' => '积分', 'docs_url' => 'https://cloud.tencent.com/document/product/1823/130060'],
        'baidu' => ['unit_name' => '积分', 'docs_url' => 'https://cloud.baidu.com/product/codingplan.html'],
    ];

    /** 本次涉及的模板（apply 幂等，仅覆盖官方预置态行、不翻转 status） */
    public const APPLY_TEMPLATES = ['volcengine', 'volcengine-ark', 'deepseek', 'moonshot', 'tencent', 'baidu'];

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

        // 旧预置档位「Agent Plan · Small」（9.9 待核实口径）已被 volcengine-ark 官方四档取代，仅删官方预置态行
        if (Schema::hasTable('coding_plan_vendor_tiers')) {
            DB::table('coding_plan_vendor_tiers')
                ->where('vendor_code', 'volcengine')
                ->where('name', 'Agent Plan · Small')
                ->where('remark', 'like', '官方档位%')
                ->delete();
        }

        foreach (self::APPLY_TEMPLATES as $code) {
            if (! DB::table('coding_plan_vendors')->where('code', $code)->exists()) {
                continue;
            }
            CodingPlanCatalog::apply($code, $now);
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('coding_plan_vendor_tiers')) {
            // 仅删除本次 apply 新增的官方预置态档位（旧数据行 remark 为 2026-09 据官方文档核对口径，不受影响）
            DB::table('coding_plan_vendor_tiers')
                ->where('vendor_code', 'volcengine-ark')
                ->whereIn('name', ['Agent Plan · Small', 'Agent Plan · Medium', 'Agent Plan · Large', 'Agent Plan · Max'])
                ->where('remark', 'like', '官方档位%')
                ->delete();
            DB::table('coding_plan_vendor_tiers')
                ->where('vendor_code', 'baidu')
                ->whereIn('name', ['个人版 · Mini', '个人版 · Lite', '个人版 · Pro', '个人版 · Max'])
                ->where('remark', 'like', '官方档位%')
                ->delete();
            DB::table('coding_plan_vendor_tiers')
                ->where('vendor_code', 'volcengine')
                ->whereIn('name', ['Coding Plan · Lite', 'Coding Plan · Pro'])
                ->where('remark', 'like', '官方档位%')
                ->delete();
        }

        if (Schema::hasTable('coding_plan_model_ratios')) {
            DB::table('coding_plan_model_ratios')
                ->where('vendor', 'volcengine-ark')
                ->whereIn('model', [
                    'doubao-seed-2.0-mini', 'doubao-seed-2.0-lite', 'deepseek-v4-flash', 'doubao-seed-2.1-turbo',
                    'doubao-seed-evolving', 'minimax-m3', 'kimi-k2.7-code', 'glm-5.2', 'glm-5.3',
                    'deepseek-v4-pro', 'kimi-k3', 'doubao-embedding-vision', 'auto',
                ])
                ->where('remark', 'like', '官方折算标准%')
                ->delete();
            DB::table('coding_plan_model_ratios')
                ->where('vendor', 'moonshot')
                ->whereIn('model', ['kimi-k3', 'kimi-k2.7-code', 'kimi-k2.7-code-highspeed', 'kimi-k2.6'])
                ->where('remark', 'like', '官方折算标准%')
                ->delete();
        }

        if (Schema::hasTable('coding_plan_vendors')) {
            DB::table('coding_plan_vendors')->where('code', 'volcengine-ark')->update([
                'name' => '火山方舟 Token Plan（按量）', 'unit_name' => '千token', 'docs_url' => null, 'updated_at' => time(),
            ]);
            DB::table('coding_plan_vendors')->whereIn('code', ['deepseek', 'moonshot'])->where('status', 0)
                ->where('remark', 'like', '预置厂商%')->update(['unit_name' => '千token', 'updated_at' => time()]);
            DB::table('coding_plan_vendors')->whereIn('code', ['tencent', 'baidu'])->where('status', 0)
                ->where('remark', 'like', '预置厂商%')->update(['unit_name' => '千token', 'updated_at' => time()]);
        }
    }
};
