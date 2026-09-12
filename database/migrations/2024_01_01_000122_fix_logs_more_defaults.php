<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * R15-1：logs 剩余 3 个无默认列（model_name/group/ip）与本地迁移声明对齐（default ''）。
     * 生产库历史建表时默认值丢失（NOT NULL 无默认），严格模式下充值/兑换类日志插入报 1364。
     * token_name 已由 000121 修正。模型层 $attributes 兜底为代码侧双保险。
     */
    public function up(): void
    {
        // 索引均已存在，仅修正列默认值
        Schema::table('logs', function (Blueprint $table): void {
            $table->string('model_name', 191)->default('')->change();
            $table->string('group', 64)->default('')->change();
            $table->string('ip', 64)->default('')->change();
        });
    }

    public function down(): void
    {
        Schema::table('logs', function (Blueprint $table): void {
            $table->string('model_name', 191)->default(null)->change();
            $table->string('group', 64)->default(null)->change();
            $table->string('ip', 64)->default(null)->change();
        });
    }
};
