<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * R15-1：logs.token_name 与本地迁移声明对齐（string default ''）。
     * 生产库历史建表时默认值丢失（NOT NULL 无默认），严格模式下
     * 充值/兑换类日志（QuotaService::addQuota 不带 token_name）插入报 1364。
     * 模型层 $attributes 兜底为代码侧双保险。
     */
    public function up(): void
    {
        // 索引已存在（logs_token_name_index），仅修正列默认值
        Schema::table('logs', function (Blueprint $table): void {
            $table->string('token_name', 191)->default('')->change();
        });
    }

    public function down(): void
    {
        Schema::table('logs', function (Blueprint $table): void {
            $table->string('token_name', 191)->default(null)->change();
        });
    }
};
