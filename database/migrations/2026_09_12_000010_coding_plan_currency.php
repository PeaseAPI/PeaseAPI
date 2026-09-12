<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * P7-1 Coding Plan 多货币：官方计价币种字段（2026-09-12，R9）
 *
 * 背景：多家官方按量价/档位价非人民币计价——
 *  - openai / google / xai / anthropic 官方文档一律 USD（元/百万 tokens 或 $/月）；
 *  - google 在 HKD 区另有港币结算口径（Cloud 香港区）；
 *  - 其余国内厂商（zhipu/deepseek/aliyun/tencent/baidu/volcengine/unicom/cmcc/
 *    siliconflow/scnet 等）官方口径均为 CNY。
 *
 * 此前 ratio 表无币种概念，USD 源若直接把美元数值存进「元」字段=资损口径错误
 * （这也是 siliconflow 官方源接入被阻塞在 P7-1 的原因）。本迁移：
 *  1) coding_plan_vendors.currency：厂商官方计价币种（ISO 4217，默认 CNY）；
 *  2) coding_plan_vendor_tiers.currency：档位标价币种（可空=NULL=继承厂商币种，
 *     以便个别档位特殊标价）；
 *  3) 预置 openai/google/anthropic（xai 尚无厂商行，接入时自会落 USD）为 USD。
 * 汇率换算与结算口径归 P7-2/P7-3（currency_rates 表 + CurrencyExchangeService）。
 */
return new class extends Migration
{
    /** 官方以 USD 计价的厂商（库内已有行的才更新） */
    public const USD_VENDORS = ['openai', 'google', 'anthropic'];

    public function up(): void
    {
        if (Schema::hasTable('coding_plan_vendors') && ! Schema::hasColumn('coding_plan_vendors', 'currency')) {
            Schema::table('coding_plan_vendors', function (Blueprint $table) {
                // 厂商官方计价币种（ISO 4217；国内厂商默认人民币）
                $table->string('currency', 8)->default('CNY')->after('unit_exchange_rate');
            });
        }

        if (Schema::hasTable('coding_plan_vendor_tiers') && ! Schema::hasColumn('coding_plan_vendor_tiers', 'currency')) {
            Schema::table('coding_plan_vendor_tiers', function (Blueprint $table) {
                // 档位标价币种（NULL=继承厂商 currency）
                $table->string('currency', 8)->nullable()->after('price_note');
            });
        }

        // USD 厂商预置（币种是新字段、管理员尚未维护，直接 update 安全）
        if (Schema::hasTable('coding_plan_vendors')) {
            DB::table('coding_plan_vendors')
                ->whereIn('code', self::USD_VENDORS)
                ->update(['currency' => 'USD', 'updated_at' => time()]);
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('coding_plan_vendor_tiers') && Schema::hasColumn('coding_plan_vendor_tiers', 'currency')) {
            Schema::table('coding_plan_vendor_tiers', function (Blueprint $table) {
                $table->dropColumn('currency');
            });
        }

        if (Schema::hasTable('coding_plan_vendors') && Schema::hasColumn('coding_plan_vendors', 'currency')) {
            Schema::table('coding_plan_vendors', function (Blueprint $table) {
                $table->dropColumn('currency');
            });
        }
    }
};
