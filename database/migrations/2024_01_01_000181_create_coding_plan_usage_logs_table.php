<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Coding Plan 账号使用流水表：记录每次提交消耗，便于审计与统计。
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('coding_plan_usage_logs', function (Blueprint $table) {
            $table->id();
            $table->unsignedInteger('account_id')->index();
            $table->string('vendor', 64)->index();
            $table->unsignedInteger('user_id')->default(0)->index();
            $table->unsignedInteger('channel_id')->default(0);
            $table->string('model', 128)->nullable();
            // 消耗的提交次数（通常为 1）
            $table->unsignedInteger('count')->default(1);
            // 消耗的 token（若上游返回）
            $table->unsignedInteger('prompt_tokens')->default(0);
            $table->unsignedInteger('completion_tokens')->default(0);
            $table->unsignedInteger('total_tokens')->default(0);
            $table->string('request_id', 128)->nullable();
            $table->boolean('success')->default(true);
            $table->text('error')->nullable();
            $table->unsignedInteger('created_at')->default(0);
            $table->index(['account_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('coding_plan_usage_logs');
    }
};