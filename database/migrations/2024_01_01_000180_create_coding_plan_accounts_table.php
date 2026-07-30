<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Coding Plan 上游账号池表
 *
 * 将多个供应商的 coding plan 账号（按 5 小时 / 周 / 月提交次数计费）
 * 纳入中转站统一管理，支持到期时间、使用计数、月使用率阈值与自动切换。
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('coding_plan_accounts', function (Blueprint $table) {
            $table->id();
            // 供应商标识（如 codingplan、pease、vendor_x），用于同供应商账号池分组轮转
            $table->string('vendor', 64)->index();
            $table->string('account_name', 128);
            // 上游 API Key / 凭证（建议加密存储）
            $table->text('api_key')->nullable();
            $table->string('base_url', 255)->nullable();
            // 账号到期时间（Unix 秒，0 表示不限）
            $table->unsignedInteger('expires_at')->default(0)->index();

            // 5 小时滚动窗口配额
            $table->unsignedInteger('quota_5h')->default(0);
            $table->unsignedInteger('used_5h')->default(0);
            $table->unsignedInteger('reset_5h_at')->default(0);
            // 周配额
            $table->unsignedInteger('quota_weekly')->default(0);
            $table->unsignedInteger('used_weekly')->default(0);
            $table->unsignedInteger('reset_weekly_at')->default(0);
            // 月配额
            $table->unsignedInteger('quota_monthly')->default(0);
            $table->unsignedInteger('used_monthly')->default(0);
            $table->unsignedInteger('reset_monthly_at')->default(0);

            // 月使用率阈值（百分比，超过则视为该账号“接近耗尽”，优先切换下一个账号）
            $table->unsignedTinyInteger('monthly_usage_threshold')->default(80);

            // 优先级（数字越小越优先使用）
            $table->unsignedInteger('priority')->default(100);
            // 状态：0=禁用 1=启用 2=已耗尽（自动标记，配额恢复后自动回到1）
            $table->unsignedTinyInteger('status')->default(1)->index();
            // 可选：关联到 channels 表的渠道（用于中转路由）
            $table->unsignedInteger('channel_id')->default(0)->index();
            // 备注
            $table->string('remark', 255)->nullable();
            $table->unsignedInteger('last_used_at')->default(0);
            $table->unsignedInteger('created_at')->default(0);
            $table->unsignedInteger('updated_at')->default(0);

            $table->index(['vendor', 'status', 'priority']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('coding_plan_accounts');
    }
};