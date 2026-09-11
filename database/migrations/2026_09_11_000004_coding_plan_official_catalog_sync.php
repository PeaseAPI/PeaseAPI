<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Coding Plan 官方模板目录落地（配套 App\Services\CodingPlanCatalog）
 *
 * 1. coding_plan_ratio_checks 新增 pending_keys 列：verify-ratios 定时校对把
 *    「待确认变更」的稳定键（kind|model|match_type）固化到最新流水，
 *    管理端「官方同步」页据此生成确认清单（应用/忽略）。
 * 2. 幂等补预置厂商：unicom-token / cmcc-token（联通/移动双产品线拆分，默认停用）。
 * 3. 幂等预置腾讯 TokenHub 官方套餐档位 8 档（通用 Lite/Standard/Pro/Max +
 *    Hy Lite/Standard/Pro/Max，2026-09-11 官方价目，逐模型积分系数官方页未定位到，不预置）。
 */
return new class extends Migration
{
    /** 预置厂商（与 000002 风格一致：默认停用，启用前须设 unit_exchange_rate 并核对比率） */
    public const PRESET_VENDORS = [
        'unicom-token' => ['中国联通 Token Plan', 2, 2, '千token', 25],
        'cmcc-token' => ['中国移动 Token Plan', 2, 2, '千token', 35],
    ];

    /** 腾讯 TokenHub 官方档位（2026-09-11 官方文档价目） */
    public const TENCENT_TIERS = [
        ['通用 · Lite', 39, '月', 780, '积分/订阅月', '约 70 轮龙虾交互', 10],
        ['通用 · Standard', 99, '月', 1980, '积分/订阅月', '约 200 轮龙虾交互', 20],
        ['通用 · Pro', 299, '月', 5980, '积分/订阅月', '', 30],
        ['通用 · Max', 599, '月', 11980, '积分/订阅月', '', 40],
        ['Hy · Lite', 28, '月', 560, '积分/订阅月', '混元 Hy3/Hy4 专用', 50],
        ['Hy · Standard', 78, '月', 1560, '积分/订阅月', '混元 Hy3/Hy4 专用', 60],
        ['Hy · Pro', 238, '月', 4760, '积分/订阅月', '混元 Hy3/Hy4 专用', 70],
        ['Hy · Max', 468, '月', 9360, '积分/订阅月', '混元 Hy3/Hy4 专用', 80],
    ];

    public function up(): void
    {
        // 1) 校对流水新增 pending_keys（变更确认清单的固化键集合，JSON 数组）
        if (Schema::hasTable('coding_plan_ratio_checks')
            && ! Schema::hasColumn('coding_plan_ratio_checks', 'pending_keys')) {
            Schema::table('coding_plan_ratio_checks', function (Blueprint $table) {
                $table->text('pending_keys')->nullable()->after('changes');
            });
        }

        if (! Schema::hasTable('coding_plan_vendors')) {
            return;
        }

        $now = time();
        $vendorRemark = '预置厂商（默认停用）：启用前请设置 unit_exchange_rate 并核对折算比率';

        foreach (self::PRESET_VENDORS as $code => [$name, $planKind, $billingMode, $unitName, $sort]) {
            $exists = DB::table('coding_plan_vendors')->where('code', $code)->exists();
            if ($exists) {
                continue;
            }
            DB::table('coding_plan_vendors')->insert([
                'code' => $code,
                'name' => $name,
                'plan_kind' => $planKind,
                'billing_mode' => $billingMode,
                'unit_name' => $unitName,
                'unit_exchange_rate' => 1,
                'docs_url' => null,
                'pricing_source_url' => null,
                'status' => 0,
                'sort' => $sort,
                'remark' => $vendorRemark,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }

        // 2) 腾讯官方档位预置（幂等：按 vendor_code+name 去重，且不覆盖管理员改过的行）
        if (! Schema::hasTable('coding_plan_vendor_tiers')) {
            return;
        }

        $tencentExists = DB::table('coding_plan_vendors')->where('code', 'tencent')->exists();
        if (! $tencentExists) {
            return;
        }

        // 智谱旧官方预置行「团队版 · 席位制」由官方模板的标准版/高级版两行替代（仅删官方预置态）
        DB::table('coding_plan_vendor_tiers')
            ->where('vendor_code', 'zhipu')
            ->where('name', '团队版 · 席位制')
            ->where('remark', 'like', '官方档位%')
            ->delete();

        $tierRemark = '官方档位（模板 v2026-09-11 核对）';
        foreach (self::TENCENT_TIERS as [$name, $price, $period, $quota, $quotaUnit, $quotaNote, $sort]) {
            $exists = DB::table('coding_plan_vendor_tiers')
                ->where('vendor_code', 'tencent')
                ->where('name', $name)
                ->exists();
            if ($exists) {
                continue;
            }
            DB::table('coding_plan_vendor_tiers')->insert([
                'vendor_code' => 'tencent',
                'name' => $name,
                'price' => $price,
                'price_note' => '',
                'period' => $period,
                'quota' => $quota,
                'quota_unit' => $quotaUnit,
                'quota_note' => $quotaNote,
                'status' => 1,
                'sort' => $sort,
                'remark' => $tierRemark,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('coding_plan_vendor_tiers')) {
            // 仅删除仍是官方预置态的腾讯档位行，避免误删管理员已维护的数据
            DB::table('coding_plan_vendor_tiers')
                ->where('vendor_code', 'tencent')
                ->where('remark', 'like', '官方档位%')
                ->delete();
        }

        if (Schema::hasTable('coding_plan_vendors')) {
            DB::table('coding_plan_vendors')
                ->whereIn('code', array_keys(self::PRESET_VENDORS))
                ->where('status', 0)
                ->where('remark', 'like', '预置厂商%')
                ->delete();
        }

        if (Schema::hasTable('coding_plan_ratio_checks')
            && Schema::hasColumn('coding_plan_ratio_checks', 'pending_keys')) {
            Schema::table('coding_plan_ratio_checks', function (Blueprint $table) {
                $table->dropColumn('pending_keys');
            });
        }
    }
};
