<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Coding Plan 积分制改造
 *
 * 1. coding_plan_accounts:
 *    - billing_mode 计费模式（1=按次提交 2=按积分折算）
 *    - unit_name 计数单位显示名（次/点/积分）
 *    - unit_exchange_rate 供应商单位 → 平台积分汇率（统一折算池口径）
 *    - quota/used 列改为 decimal，支持积分小数
 * 2. coding_plan_usage_logs: 新增 units/credits/meta 列，count 支持 decimal
 * 3. 新表 coding_plan_vendors: 供应商元配置（计费模式/单位/汇率）
 * 4. 新表 coding_plan_model_ratios: 模型 → 单位折算比率表（积分制核心）
 */
return new class extends Migration
{
    public function up(): void
    {
        // ---------- coding_plan_accounts 新增积分制字段 ----------
        // MySQL DDL 非事务性：失败重放时需按列/表是否存在跳过已应用部分
        if (! Schema::hasColumn('coding_plan_accounts', 'billing_mode')) {
            Schema::table('coding_plan_accounts', function (Blueprint $table) {
                $table->unsignedTinyInteger('billing_mode')->default(1)->after('vendor');
                $table->string('unit_name', 16)->default('')->after('billing_mode');
                $table->decimal('unit_exchange_rate', 12, 6)->default(1)->after('unit_name');
            });
        }

        // 配额/消耗列支持小数（积分制折算会产生小数消耗）
        Schema::table('coding_plan_accounts', function (Blueprint $table) {
            foreach (['quota_5h', 'used_5h', 'quota_weekly', 'used_weekly', 'quota_monthly', 'used_monthly'] as $column) {
                $table->decimal($column, 16, 2)->unsigned()->default(0)->change();
            }
        });

        // ---------- coding_plan_usage_logs 支持积分折算明细 ----------
        if (! Schema::hasColumn('coding_plan_usage_logs', 'units')) {
            Schema::table('coding_plan_usage_logs', function (Blueprint $table) {
                // 消耗的供应商原生单位（次数或积分，可有小数）
                $table->decimal('units', 14, 4)->unsigned()->default(0)->after('count');
                // 折算后的平台积分（units × unit_exchange_rate）
                $table->decimal('credits', 14, 4)->unsigned()->default(0)->after('units');
                // 比率快照（比率表匹配明细 JSON，便于审计回溯）
                $table->text('meta')->nullable()->after('error');
            });
        }

        Schema::table('coding_plan_usage_logs', function (Blueprint $table) {
            $table->decimal('count', 14, 2)->unsigned()->default(1)->change();
        });

        // ---------- 供应商元配置表 ----------
        Schema::create('coding_plan_vendors', function (Blueprint $table) {
            $table->id();
            // 供应商标识（与 coding_plan_accounts.vendor / 比率表 vendor 对应）
            $table->string('code', 64)->unique();
            $table->string('name', 128);
            $table->string('logo', 255)->nullable();
            // 默认计费模式：1=按次 2=按积分
            $table->unsignedTinyInteger('billing_mode')->default(1);
            $table->string('unit_name', 16)->default('');
            // 该供应商单位 → 平台积分的默认汇率（账号级可覆盖）
            $table->decimal('unit_exchange_rate', 12, 6)->default(1);
            $table->string('docs_url', 255)->nullable();
            $table->unsignedTinyInteger('status')->default(1);
            $table->unsignedInteger('sort')->default(0);
            $table->string('remark', 255)->nullable();
            $table->unsignedInteger('created_at')->default(0);
            $table->unsignedInteger('updated_at')->default(0);
        });

        // ---------- 模型折算比率表 ----------
        Schema::create('coding_plan_model_ratios', function (Blueprint $table) {
            $table->id();
            $table->string('vendor', 64)->index();
            // 模型名（exact 全等匹配 / prefix 前缀匹配，前缀最长优先）
            $table->string('model', 128);
            // 匹配方式：exact | prefix
            $table->string('match_type', 10)->default('exact');
            // 计费口径：per_request=按次 | per_1k_tokens=按千 token
            $table->string('cost_mode', 16)->default('per_request');
            // 单位成本：每次请求（或每千 token）消耗的供应商单位数
            $table->decimal('unit_cost', 12, 4)->unsigned()->default(1);
            $table->unsignedTinyInteger('status')->default(1);
            $table->unsignedInteger('sort')->default(0);
            $table->string('remark', 255)->nullable();
            $table->unsignedInteger('created_at')->default(0);
            $table->unsignedInteger('updated_at')->default(0);
            $table->index(['vendor', 'model']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('coding_plan_model_ratios');
        Schema::dropIfExists('coding_plan_vendors');

        Schema::table('coding_plan_usage_logs', function (Blueprint $table) {
            $table->dropColumn(['units', 'credits', 'meta']);
        });

        Schema::table('coding_plan_usage_logs', function (Blueprint $table) {
            $table->unsignedInteger('count')->default(1)->change();
        });

        Schema::table('coding_plan_accounts', function (Blueprint $table) {
            foreach (['quota_5h', 'used_5h', 'quota_weekly', 'used_weekly', 'quota_monthly', 'used_monthly'] as $column) {
                $table->unsignedInteger($column)->default(0)->change();
            }
        });

        Schema::table('coding_plan_accounts', function (Blueprint $table) {
            $table->dropColumn(['billing_mode', 'unit_name', 'unit_exchange_rate']);
        });
    }
};
