<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Coding Plan 折算比率校对机制
 *
 * 1. coding_plan_vendors: 新增 pricing_source_url（可选结构化定价源，
 *    校对任务每 6 小时拉取并 diff，发现新模型/变价/下架只记录不自动改价）
 * 2. 新表 coding_plan_ratio_checks: 校对流水（每供应商每次校对一条，
 *    记录 stale 数、变更数、源状态与变更明细，公开介绍页展示最后核对时间）
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('coding_plan_vendors') && ! Schema::hasColumn('coding_plan_vendors', 'pricing_source_url')) {
            Schema::table('coding_plan_vendors', function (Blueprint $table) {
                // 可选：结构化定价源（JSON），供校对任务自动 diff（不自动改价）
                $table->string('pricing_source_url', 255)->nullable()->after('docs_url');
            });
        }

        if (! Schema::hasTable('coding_plan_ratio_checks')) {
            Schema::create('coding_plan_ratio_checks', function (Blueprint $table) {
                $table->id();
                // 供应商标识（与 coding_plan_vendors.code 对应；比率表孤儿厂商也会被校对）
                $table->string('vendor', 64)->index();
                // 校对时间（Unix 秒）
                $table->unsignedInteger('checked_at')->default(0)->index();
                // 超过核对窗口（待人工复核）的启用比率数
                $table->unsignedInteger('stale_count')->default(0);
                // 定价源 diff 出的变更数（新增+变价，不含下架）
                $table->unsignedInteger('change_count')->default(0);
                // 定价源状态：0=未配置源 1=拉取成功 2=拉取失败
                $table->unsignedTinyInteger('source_status')->default(0);
                // 变更明细 JSON（new/changed/missing），人工确认后由管理员改比率
                $table->text('changes')->nullable();
                $table->unsignedInteger('created_at')->default(0);
                $table->index(['vendor', 'checked_at']);
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('coding_plan_ratio_checks');

        if (Schema::hasTable('coding_plan_vendors') && Schema::hasColumn('coding_plan_vendors', 'pricing_source_url')) {
            Schema::table('coding_plan_vendors', function (Blueprint $table) {
                $table->dropColumn('pricing_source_url');
            });
        }
    }
};
