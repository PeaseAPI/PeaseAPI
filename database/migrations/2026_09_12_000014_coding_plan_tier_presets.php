<?php

use App\Services\CodingPlanCatalog;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * P2-1：落地 SCNet（超算互联网）官方模板 —— 厂商行 + 套餐档位（基础/标准/高级/旗舰，
 * 活动价 30/110/265/764 元 CNY，月度 Credits 6 万~180 万）+ 扣减倍率示例行（status=0 参考）。
 *
 * 与 000005/000006 同构：档位数据统一由 CodingPlanCatalog::templates()['scnet'] 维护，
 * apply 幂等（新厂商 status=0 默认停用；已存在的行仅覆盖官方预置态、不翻转 status）。
 * unicom/aliyun 档位此前已由 000006/000007 预置，本迁移不涉及。
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('coding_plan_vendors') || ! Schema::hasTable('coding_plan_vendor_tiers')) {
            return;
        }

        $now = time();
        $vendorExists = DB::table('coding_plan_vendors')->where('code', 'scnet')->exists();

        CodingPlanCatalog::apply('scnet', $now);

        // P7-1：厂商/档位币种列补 CNY（新落地行该列为空）
        if (Schema::hasColumn('coding_plan_vendors', 'currency')) {
            DB::table('coding_plan_vendors')->where('code', 'scnet')
                ->whereNull('currency')
                ->update(['currency' => 'CNY', 'updated_at' => $now]);
        }
        if (Schema::hasColumn('coding_plan_vendor_tiers', 'currency')) {
            DB::table('coding_plan_vendor_tiers')->where('vendor_code', 'scnet')
                ->whereNull('currency')
                ->update(['currency' => 'CNY', 'updated_at' => $now]);
        }

        if (! $vendorExists) {
            // 新建厂商行备注补记来源任务号
            DB::table('coding_plan_vendors')->where('code', 'scnet')
                ->where('remark', 'like', '官方模板落地%')
                ->update(['remark' => '官方模板落地（默认停用，P2-1）：启用前请设置 unit_exchange_rate 并核对折算比率', 'updated_at' => $now]);
        }
    }

    public function down(): void
    {
        if (! Schema::hasTable('coding_plan_vendor_tiers')) {
            return;
        }

        // 仅删除本次 apply 新增的官方预置态行（管理员改过的行 remark 已变，不受影响）
        DB::table('coding_plan_vendor_tiers')->where('vendor_code', 'scnet')
            ->whereIn('name', ['基础版', '标准版', '高级版', '旗舰版'])
            ->where('remark', 'like', CodingPlanCatalog::TIER_REMARK_PREFIX.'%')
            ->delete();

        if (Schema::hasTable('coding_plan_model_ratios')) {
            DB::table('coding_plan_model_ratios')->where('vendor', 'scnet')
                ->whereIn('model', ['glm-5.3', 'kimi-k3'])
                ->where('remark', 'like', CodingPlanCatalog::RATIO_REMARK_PREFIX.'%')
                ->delete();
        }
    }
};
