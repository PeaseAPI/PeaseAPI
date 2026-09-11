<?php

declare(strict_types=1);

use App\Services\CodingPlanCatalog;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Coding Plan 联通官方价目补全（2026-09-11 经代理直连抓取官方全量文档核对）
 *
 * 官方页（support.cucloud.cn/document/127/591/2357）为超大 SPA payload，
 * 本轮经本地代理直连取得完整文档（16MB 全量包），提取出：
 *  1. Coding Plan（arcid=7015）：Lite/Pro 40/200 元/月，按「模型调用次数」扣减
 *     （每订阅月 18,000/90,000 次，附 5 小时/周限额）→ per_request 1:1 折算；
 *  2. Token Plan（arcid=7080）：个人版 Lite/Pro/Max 15/30/45 元/月 → 600/1,200/1,800 万 tokens（1:1）；
 *     团队版 Lite/Pro/Max 198/698/1398 元/月 → 25,000/100,000/250,000 credits，
 *     官方线性折算示例 → 1 credit ≈ 0.01 元，每千 tokens 系数 V4-Pro 0.93 / V4-Flash 0.07 / M2.5 0.11。
 * 3. 通过 CodingPlanCatalog（单一事实源）幂等落地：档位公开态，比率行全部停用（管理员核对后启用）。
 */
return new class extends Migration
{
    /** 仅修正仍为预置态的厂商行（status=0 且 remark 为预置/模板落地），不触碰管理员维护的数据 */
    public const VENDOR_FIXES = [
        'unicom' => ['unit_name' => '次', 'docs_url' => 'https://support.cucloud.cn/document/127/591/2357.html?id=2357&arcid=7015&lang=zh'],
        'unicom-token' => ['docs_url' => 'https://support.cucloud.cn/document/127/591/2357.html?id=2357&arcid=7080&lang=zh'],
    ];

    /** 本次涉及的模板（apply 幂等，仅覆盖官方预置态行、不翻转 status） */
    public const APPLY_TEMPLATES = ['unicom', 'unicom-token'];

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
            if (! DB::table('coding_plan_vendors')->where('code', $code)->exists()) {
                continue;
            }
            CodingPlanCatalog::apply($code, $now);
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('coding_plan_vendor_tiers')) {
            // 仅删除本次 apply 新增的官方预置态档位（壳模板时期无档位，不存在旧数据冲突）
            DB::table('coding_plan_vendor_tiers')
                ->where('vendor_code', 'unicom')
                ->whereIn('name', ['Coding Plan · Lite', 'Coding Plan · Pro'])
                ->where('remark', 'like', '官方档位%')
                ->delete();
            DB::table('coding_plan_vendor_tiers')
                ->where('vendor_code', 'unicom-token')
                ->whereIn('name', ['个人版 · Lite', '个人版 · Pro', '个人版 · Max', '团队版 · Lite', '团队版 · Pro', '团队版 · Max'])
                ->where('remark', 'like', '官方档位%')
                ->delete();
        }

        if (Schema::hasTable('coding_plan_model_ratios')) {
            DB::table('coding_plan_model_ratios')
                ->where('vendor', 'unicom')
                ->whereIn('model', ['aisp-auto-route', 'DeepSeek-', 'glm-', 'Qwen', 'kimi-', 'MiniMax-'])
                ->where('remark', 'like', '官方折算标准%')
                ->delete();
            DB::table('coding_plan_model_ratios')
                ->where('vendor', 'unicom-token')
                ->whereIn('model', ['DeepSeek-V4-Pro', 'DeepSeek-V4-Flash', 'MiniMax-M2.5', 'DeepSeek-', 'MiniMax-'])
                ->where('remark', 'like', '官方折算标准%')
                ->delete();
        }

        if (Schema::hasTable('coding_plan_vendors')) {
            DB::table('coding_plan_vendors')->where('code', 'unicom')->where('status', 0)
                ->where('remark', 'like', '预置厂商%')->update(['unit_name' => '点', 'updated_at' => time()]);
        }
    }
};
