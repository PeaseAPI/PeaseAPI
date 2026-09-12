<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * P2-1：Coding Plan 厂商促销/价格变动/模型退市活动表。
 *
 * 列语义（对齐任务清单 P2-1）：
 * - vendor            厂商代码（coding_plan_vendors.code）
 * - kind              活动类型：discount=限时折扣 / free=免费开放 / price_change=价格变动 /
 *                     model_retirement=模型退市
 * - discount          折扣乘数（(0,1)，仅 kind=discount 时有效；0.8=8 折）
 * - starts_at/ends_at Unix 秒；ends_at 可空（0）=官方未公布截止（长期有效，介绍页不显示倒计时）
 * - status            1=启用（前台展示/参与提醒）0=停用
 * - remind_days       到期前提醒天数（默认 7，P2-3 提醒链路用）
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('coding_plan_promotions')) {
            return;
        }

        Schema::create('coding_plan_promotions', function (Blueprint $table) {
            $table->id();
            $table->string('vendor', 64)->index()->comment('厂商代码');
            $table->string('kind', 16)->default('discount')->index()->comment('discount/free/price_change/model_retirement');
            $table->string('title', 128)->comment('活动标题');
            $table->text('description')->nullable()->comment('活动说明（正文/适用范围/注意事项）');
            $table->decimal('discount', 6, 3)->nullable()->comment('折扣乘数 (0,1)，仅 discount 类型');
            $table->unsignedInteger('starts_at')->default(0)->index()->comment('开始时间 Unix 秒');
            $table->unsignedInteger('ends_at')->nullable()->index()->comment('结束时间 Unix 秒，NULL=官方未公布');
            $table->string('source_url', 512)->nullable()->comment('官方来源 URL');
            $table->unsignedTinyInteger('status')->default(1)->index()->comment('1=启用 0=停用');
            $table->unsignedTinyInteger('remind_days')->default(7)->comment('到期前提醒天数');
            $table->unsignedInteger('sort')->default(0);
            $table->string('remark', 255)->nullable();
            $table->unsignedInteger('created_at')->default(0);
            $table->unsignedInteger('updated_at')->default(0);
            $table->index(['vendor', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('coding_plan_promotions');
    }
};
