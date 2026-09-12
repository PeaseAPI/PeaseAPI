<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * P7-4：用户结算货币偏好。
 * - users.settlement_currency：用户偏好的展示/结算币种（ISO 4217 大写，如 USD/HKD）
 * - NULL = 未设置，跟随平台基准币种（CNY）；合法值须 CurrencyExchangeService::isSupported()
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('users')) {
            return;
        }
        if (Schema::hasColumn('users', 'settlement_currency')) {
            return;
        }

        Schema::table('users', function (Blueprint $table) {
            $table->string('settlement_currency', 8)->nullable()->after('stripe_customer')->comment('结算货币偏好（NULL=跟随平台基准 CNY）');
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('users') || ! Schema::hasColumn('users', 'settlement_currency')) {
            return;
        }

        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('settlement_currency');
        });
    }
};
