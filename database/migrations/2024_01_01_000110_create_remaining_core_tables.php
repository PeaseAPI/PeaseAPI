<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // 模型所有者表
        Schema::create('model_owners', function (Blueprint $table) {
            $table->id();
            $table->string('name', 255)->unique();
            $table->timestamps();
        });

        // 模型元数据表
        Schema::create('model_metas', function (Blueprint $table) {
            $table->id();
            $table->string('model_name', 255)->unique();
            $table->unsignedBigInteger('owner_id')->nullable();
            $table->string('description', 512)->default('');
            $table->string('tags', 512)->default('');
            $table->timestamps();

            $table->foreign('owner_id')->references('id')->on('model_owners')->nullOnDelete();
        });

        // 厂商元数据表
        Schema::create('vendor_metas', function (Blueprint $table) {
            $table->id();
            $table->string('name', 255)->unique();
            $table->string('description', 512)->default('');
            $table->string('icon', 512)->default('');
            $table->string('website', 512)->default('');
            $table->timestamps();
        });

        // 预填组表
        Schema::create('prefill_groups', function (Blueprint $table) {
            $table->id();
            $table->string('name', 255);
            $table->text('description')->nullable();
            $table->text('prefills'); // JSON array
            $table->timestamps();
        });

        // 系统任务表
        Schema::create('system_tasks', function (Blueprint $table) {
            $table->id();
            $table->string('type', 64);
            $table->string('status', 32)->default('pending');
            $table->text('params')->nullable();
            $table->text('result')->nullable();
            $table->unsignedBigInteger('created_by')->default(0);
            $table->timestamp('started_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();
        });

        // 系统实例表
        Schema::create('system_instances', function (Blueprint $table) {
            $table->id();
            $table->string('node_name', 255)->unique();
            $table->string('ip', 64)->default('');
            $table->json('capabilities')->nullable();
            $table->timestamp('last_heartbeat')->nullable();
            $table->timestamps();
        });

        // Passkey 凭证表
        Schema::create('passkeys', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('user_id');
            $table->string('credential_id', 512)->unique();
            $table->text('public_key');
            $table->unsignedBigInteger('counter')->default(0);
            $table->string('name', 255)->default('');
            $table->string('aaguid', 64)->default('');
            $table->string('transports', 255)->default('');
            $table->timestamps();

            $table->foreign('user_id')->references('id')->on('users')->cascadeOnDelete();
            $table->index('user_id');
        });

        // 双因素认证表
        Schema::create('two_fa', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('user_id')->unique();
            $table->string('secret', 255);
            $table->text('backup_codes')->nullable();
            $table->boolean('enabled')->default(false);
            $table->timestamps();

            $table->foreign('user_id')->references('id')->on('users')->cascadeOnDelete();
        });

        // 自定义 OAuth 提供商表
        Schema::create('custom_oauth_providers', function (Blueprint $table) {
            $table->id();
            $table->string('name', 255)->unique();
            $table->string('client_id', 512);
            $table->text('client_secret');
            $table->string('scopes', 512)->default('');
            $table->string('authorize_url', 512)->default('');
            $table->string('token_url', 512)->default('');
            $table->string('userinfo_url', 512)->default('');
            $table->text('well_known_url')->nullable();
            $table->string('icon', 512)->default('');
            $table->timestamps();
        });

        // 外部身份声明表
        Schema::create('external_identity_claims', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('user_id');
            $table->string('provider', 64);
            $table->string('provider_user_id', 255);
            $table->string('email', 255)->default('');
            $table->string('username', 255)->default('');
            $table->string('avatar', 512)->default('');
            $table->text('raw_data')->nullable();
            $table->timestamps();

            $table->foreign('user_id')->references('id')->on('users')->cascadeOnDelete();
            $table->index(['provider', 'provider_user_id']);
        });

        // 用户会话表
        Schema::create('user_sessions', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('user_id');
            $table->string('token', 255)->unique();
            $table->string('ip', 64)->default('');
            $table->string('user_agent', 512)->default('');
            $table->timestamp('expires_at')->nullable();
            $table->timestamps();

            $table->foreign('user_id')->references('id')->on('users')->cascadeOnDelete();
            $table->index('user_id');
        });

        // OAuth 绑定表
        Schema::create('user_oauth_bindings', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('user_id');
            $table->string('provider', 64);
            $table->string('provider_user_id', 255);
            $table->string('provider_username', 255)->default('');
            $table->string('provider_avatar', 512)->default('');
            $table->timestamps();

            $table->foreign('user_id')->references('id')->on('users')->cascadeOnDelete();
            $table->unique(['provider', 'provider_user_id']);
            $table->index('user_id');
        });

        // RBAC 角色表
        Schema::create('authz_roles', function (Blueprint $table) {
            $table->id();
            $table->string('name', 255)->unique();
            $table->string('description', 512)->default('');
            $table->timestamps();
        });

        // Casbin 规则表
        Schema::create('casbin_rules', function (Blueprint $table) {
            $table->id();
            $table->string('ptype', 64)->default('');
            $table->string('v0', 128)->default('');
            $table->string('v1', 128)->default('');
            $table->string('v2', 128)->default('');
            $table->string('v3', 128)->default('');
            $table->string('v4', 128)->default('');
            $table->string('v5', 128)->default('');
        });

        // 用量数据表
        Schema::create('usedata', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('user_id');
            $table->date('date');
            $table->bigInteger('request_count')->default(0);
            $table->bigInteger('prompt_tokens')->default(0);
            $table->bigInteger('completion_tokens')->default(0);
            $table->bigInteger('quota_used')->default(0);
            $table->timestamps();

            $table->foreign('user_id')->references('id')->on('users')->cascadeOnDelete();
            $table->unique(['user_id', 'date']);
        });

        // 用量流水表
        Schema::create('usedata_flows', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('user_id');
            $table->string('model_name', 255)->default('');
            $table->string('type', 32)->default('');
            $table->bigInteger('quota_used')->default(0);
            $table->bigInteger('prompt_tokens')->default(0);
            $table->bigInteger('completion_tokens')->default(0);
            $table->timestamps();

            $table->foreign('user_id')->references('id')->on('users')->cascadeOnDelete();
            $table->index('user_id');
        });

        // 排行榜表
        Schema::create('usedata_rankings', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('user_id');
            $table->string('period', 32); // daily, weekly, monthly
            $table->bigInteger('quota_used')->default(0);
            $table->bigInteger('request_count')->default(0);
            $table->integer('rank')->default(0);
            $table->timestamps();

            $table->foreign('user_id')->references('id')->on('users')->cascadeOnDelete();
            $table->unique(['user_id', 'period']);
        });

        // 认证流程表
        Schema::create('auth_flows', function (Blueprint $table) {
            $table->id();
            $table->string('flow_token', 128)->unique();
            $table->string('action', 64);
            $table->json('payload')->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->timestamps();
        });

        // 模型额外信息表
        Schema::create('model_extras', function (Blueprint $table) {
            $table->id();
            $table->string('model_name', 255)->unique();
            $table->string('input_price', 64)->default('0');
            $table->string('output_price', 64)->default('0');
            $table->integer('max_tokens')->default(0);
            $table->integer('max_context')->default(0);
            $table->string('vision', 8)->default('false');
            $table->string('function_call', 8)->default('false');
            $table->string('streaming', 8)->default('true');
            $table->text('description')->nullable();
            $table->timestamps();
        });

        // 缺失模型表
        Schema::create('missing_models', function (Blueprint $table) {
            $table->id();
            $table->string('model_name', 255);
            $table->unsignedBigInteger('channel_id')->default(0);
            $table->timestamp('reported_at')->nullable();
            $table->timestamps();

            $table->index('model_name');
        });

        // 数据库时间表
        Schema::create('db_times', function (Blueprint $table) {
            $table->id();
            $table->timestamp('db_time');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('db_times');
        Schema::dropIfExists('missing_models');
        Schema::dropIfExists('model_extras');
        Schema::dropIfExists('auth_flows');
        Schema::dropIfExists('usedata_rankings');
        Schema::dropIfExists('usedata_flows');
        Schema::dropIfExists('usedata');
        Schema::dropIfExists('casbin_rules');
        Schema::dropIfExists('authz_roles');
        Schema::dropIfExists('user_oauth_bindings');
        Schema::dropIfExists('user_sessions');
        Schema::dropIfExists('external_identity_claims');
        Schema::dropIfExists('custom_oauth_providers');
        Schema::dropIfExists('two_fa');
        Schema::dropIfExists('passkeys');
        Schema::dropIfExists('system_instances');
        Schema::dropIfExists('system_tasks');
        Schema::dropIfExists('prefill_groups');
        Schema::dropIfExists('vendor_metas');
        Schema::dropIfExists('model_metas');
        Schema::dropIfExists('model_owners');
    }
};
