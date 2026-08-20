<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 扩展 subscription_plans：支持 Coding Plan 类型套餐
 *
 * - plan_type: quota（按额度计费，默认）| coding_plan（按提交次数计费）
 * - coding_vendor: 关联的账号池供应商标识
 * - coding_*: 该套餐每次/周期可消耗的提交次数配额
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('subscription_plans', function (Blueprint $table) {
            $table->string('plan_type', 20)->default('quota')->after('quota');
            $table->string('coding_vendor', 64)->nullable()->after('plan_type');
            // 套餐每次请求消耗的提交次数（通常为 1）
            $table->unsignedInteger('coding_submits_per_request')->default(1)->after('coding_vendor');
            // 套餐周期内总提交次数上限（0 表示不限，仅受账号池约束）
            $table->unsignedInteger('coding_quota')->default(0)->after('coding_submits_per_request');
            $table->index(['plan_type', 'status']);
        });
    }

    public function down(): void
    {
        Schema::table('subscription_plans', function (Blueprint $table) {
            $table->dropIndex(['plan_type', 'status']);
            $table->dropColumn(['plan_type', 'coding_vendor', 'coding_submits_per_request', 'coding_quota']);
        });
    }
};
