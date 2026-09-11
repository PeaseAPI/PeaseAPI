<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * 角色等级对齐 new-api 语义（USER=1 / ADMIN=10 / ROOT=100）
 * 旧 UserRole 枚举误将 USER 定义为 2，SPA 注册产生的 role=2 需归一为 1。
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->index('role');
        });
        DB::table('users')->where('role', 2)->update(['role' => 1]);
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropIndex(['role']);
        });
    }
};
