<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // 订阅订单表
        Schema::create('subscription_orders', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('user_id')->default(0);
            $table->unsignedBigInteger('plan_id')->default(0);
            $table->string('trade_no', 64)->default('')->index();
            $table->decimal('amount', 10, 2)->default(0);
            $table->string('currency', 10)->default('CNY');
            $table->tinyInteger('status')->default(0)->comment('0=待支付 1=已支付 2=已取消');
            $table->string('payment_method', 32)->default('');
            $table->string('payment_provider', 32)->default('');
            $table->bigInteger('period_start')->default(0);
            $table->bigInteger('period_end')->default(0);
            $table->bigInteger('created_at')->default(0);
            $table->bigInteger('paid_at')->default(0);
            $table->bigInteger('cancelled_at')->default(0);

            $table->index('user_id');
            $table->index('plan_id');
            $table->index('status');
        });

        // 订阅预扣记录表
        Schema::create('subscription_pre_consume_records', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('user_id')->default(0);
            $table->unsignedBigInteger('subscription_id')->default(0);
            $table->bigInteger('quota')->default(0);
            $table->string('request_id', 64)->default('')->index();
            $table->bigInteger('created_at')->default(0);
            $table->tinyInteger('consumed')->default(0)->comment('0=未结算 1=已结算');
            $table->bigInteger('consumed_at')->default(0);

            $table->index('user_id');
            $table->index('subscription_id');
            $table->index(['user_id', 'subscription_id']);
        });

        // 用户订阅表
        Schema::create('user_subscriptions', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('user_id')->default(0);
            $table->unsignedBigInteger('plan_id')->default(0);
            $table->unsignedBigInteger('order_id')->default(0);
            $table->bigInteger('start_at')->default(0);
            $table->bigInteger('end_at')->default(0);
            $table->tinyInteger('status')->default(1)->comment('1=有效 0=失效 2=过期');
            $table->bigInteger('quota_used')->default(0);
            $table->bigInteger('quota_total')->default(0);
            $table->bigInteger('quota_reset_at')->default(0);
            $table->bigInteger('last_reset_at')->default(0);
            $table->string('upgrade_group', 64)->default('');
            $table->string('group_before', 64)->default('');
            $table->tinyInteger('auto_renew')->default(0);
            $table->bigInteger('cancelled_at')->default(0);
            $table->bigInteger('created_at')->default(0);
            $table->bigInteger('updated_at')->default(0);

            $table->index('user_id');
            $table->index('plan_id');
            $table->index('status');
        });

        // 注：user_tasks / user_task_records 已由
        // 2024_01_01_000080_create_checkins_tasks_tables 创建，此处不再重复建表。
    }

    public function down(): void
    {
        Schema::dropIfExists('user_subscriptions');
        Schema::dropIfExists('subscription_pre_consume_records');
        Schema::dropIfExists('subscription_orders');
    }
};
