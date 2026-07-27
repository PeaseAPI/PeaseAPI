<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('users', function (Blueprint $table) {
            $table->id();
            $table->string('username', 191)->unique();
            $table->string('password', 255);
            $table->string('display_name', 191)->default('')->index();
            $table->integer('role')->default(1)->comment('1=普通用户, 10=管理员, 100=超级管理员');
            $table->integer('status')->default(1)->comment('1=启用, 0=禁用');
            $table->string('email', 191)->default('')->index();
            $table->string('github_id', 191)->default('')->index();
            $table->string('discord_id', 191)->default('')->index();
            $table->string('oidc_id', 191)->default('')->index();
            $table->string('wechat_id', 191)->default('')->index();
            $table->string('telegram_id', 191)->default('')->index();
            $table->string('linux_do_id', 191)->default('')->index();
            $table->char('access_token', 32)->nullable()->unique()->comment('系统管理token');
            $table->integer('quota')->default(0);
            $table->integer('used_quota')->default(0);
            $table->integer('request_count')->default(0);
            $table->string('group', 64)->default('default');
            $table->string('aff_code', 32)->unique()->comment('邀请码');
            $table->integer('aff_count')->default(0);
            $table->integer('aff_quota')->default(0)->comment('邀请剩余额度');
            $table->integer('aff_history')->default(0)->comment('邀请历史额度');
            $table->unsignedInteger('inviter_id')->default(null)->nullable()->index();
            $table->text('setting')->nullable()->comment('JSON 用户设置');
            $table->string('remark', 255)->default('');
            $table->string('stripe_customer', 64)->default(null)->nullable()->index();
            $table->bigInteger('created_at')->unsigned();
            $table->bigInteger('last_login_at')->unsigned()->default(0);
            $table->timestamp('deleted_at')->nullable()->index();
        });

        Schema::create('password_reset_tokens', function (Blueprint $table) {
            $table->string('email')->primary();
            $table->string('token');
            $table->timestamp('created_at')->nullable();
        });

        Schema::create('sessions', function (Blueprint $table) {
            $table->string('id')->primary();
            $table->foreignId('user_id')->nullable()->index();
            $table->string('ip_address', 45)->nullable();
            $table->text('user_agent')->nullable();
            $table->longText('payload');
            $table->integer('last_activity')->index();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sessions');
        Schema::dropIfExists('password_reset_tokens');
        Schema::dropIfExists('users');
    }
};