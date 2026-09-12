<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * P7-2 汇率配置表（2026-09-12，R9 多货币体系）
 *
 * currency_rates：官方计价币种 → 平台基准币种（CNY）的汇率，管理端维护：
 *  - code：ISO 4217 币种（主键，如 USD/HKD/EUR）；
 *  - rate：1 单位该币种 = rate 人民币（相对基准 CNY；USD≈7.1 表示 1 美元折 7.1 元）；
 *  - source：来源（manual=管理员手工 / api=自动拉取，便于审计与后续自动化）。
 *
 * 读取优先级（CurrencyExchangeService::rate()）：
 *  1. currency_rates 有该币种行 → 用表值；
 *  2. USD 无行 → 回落既有 Option 'UsdExchangeRate'（平台既有美元汇率配置）；
 *  3. CNY 恒为 1.0（基准币种）；其余币种无行 → null（不可折算，调用方应拒绝或提示）。
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('currency_rates')) {
            Schema::create('currency_rates', function (Blueprint $table) {
                // ISO 4217 币种代码（USD/HKD/EUR/...，基准 CNY 不需行）
                $table->string('code', 8)->primary();
                // 1 单位该币种 = rate 人民币（相对基准 CNY）
                $table->decimal('rate', 14, 6);
                // 来源：manual=管理端手工 / api=自动同步
                $table->string('source', 16)->default('manual');
                $table->string('remark', 255)->nullable();
                // 最后更新时间（Unix 秒），管理端展示判断时效
                $table->unsignedInteger('updated_at')->default(0);
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('currency_rates');
    }
};
