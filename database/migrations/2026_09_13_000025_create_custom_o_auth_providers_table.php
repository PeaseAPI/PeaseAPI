<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * QA-27：custom_o_auth_providers 表缺失——OAuthController 自定义 OAuth 管理 CRUD
 * 与 SPA 管理端（oauth-providers.tsx）已就绪，但表从未建，任一调用即 SQLSTATE 42S02。
 * 字段对齐 controller create/update only 白名单。
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('custom_o_auth_providers', function (Blueprint $table) {
            $table->id();
            $table->string('name')->unique();
            $table->string('client_id');
            $table->string('client_secret');
            $table->string('icon')->nullable();
            $table->text('scopes')->nullable();
            $table->string('authorize_url')->nullable();
            $table->string('token_url')->nullable();
            $table->string('userinfo_url')->nullable();
            $table->string('well_known_url')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('custom_o_auth_providers');
    }
};
