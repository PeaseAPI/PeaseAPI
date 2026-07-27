<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('phone', 20)->default('')->index()->after('email');
        });

        // 复用 password_reset_tokens 表（增加 phone 字段以支持手机号重置）
        if (!Schema::hasColumn('password_reset_tokens', 'phone')) {
            Schema::table('password_reset_tokens', function (Blueprint $table) {
                $table->string('phone', 20)->default('')->index()->after('email');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('password_reset_tokens', 'phone')) {
            Schema::table('password_reset_tokens', function (Blueprint $table) {
                $table->dropColumn('phone');
            });
        }
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('phone');
        });
    }
};