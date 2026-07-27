<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('subscription_plans', function (Blueprint $table) {
            $table->id();
            $table->string('title', 128);
            $table->string('subtitle', 255)->default('');
            $table->decimal('price_amount', 10, 6)->default(0);
            $table->string('currency', 8)->default('USD');
            $table->string('duration_unit', 16)->default('month');
            $table->integer('duration_value')->default(1);
            $table->bigInteger('custom_seconds')->default(0);
            $table->boolean('enabled')->default(true);
            $table->integer('sort_order')->default(0);
            $table->boolean('allow_balance_pay')->nullable();
            $table->boolean('allow_wallet_overflow')->nullable();
            $table->string('stripe_price_id', 128)->default('');
            $table->string('creem_product_id', 128)->default('');
            $table->string('waffo_pancake_product_id', 128)->default('');
            $table->integer('max_purchase_per_user')->default(0);
            $table->string('upgrade_group', 64)->default('');
            $table->string('downgrade_group', 64)->default('');
            $table->bigInteger('total_amount')->default(0);
            $table->string('quota_reset_period', 16)->default('never');
            $table->bigInteger('quota_reset_custom_seconds')->default(0);
            $table->bigInteger('created_at')->unsigned();
            $table->bigInteger('updated_at')->unsigned();
        });

        Schema::create('subscription_orders', function (Blueprint $table) {
            $table->id();
            $table->unsignedInteger('user_id')->index();
            $table->unsignedInteger('plan_id')->index();
            $table->string('trade_no', 255)->unique();
            $table->decimal('amount', 10, 6);
            $table->string('currency', 8)->default('USD');
            $table->string('status', 20);
            $table->string('payment_method', 50);
            $table->string('payment_provider', 50)->default('');
            $table->bigInteger('period_start')->unsigned();
            $table->bigInteger('period_end')->unsigned();
            $table->bigInteger('created_at')->unsigned();
            $table->bigInteger('paid_at')->unsigned()->default(0);
            $table->bigInteger('cancelled_at')->unsigned()->default(0);
        });

        Schema::create('user_subscriptions', function (Blueprint $table) {
            $table->id();
            $table->unsignedInteger('user_id')->index();
            $table->unsignedInteger('plan_id')->index();
            $table->unsignedInteger('order_id');
            $table->bigInteger('start_at')->unsigned();
            $table->bigInteger('end_at')->unsigned();
            $table->string('status', 20);
            $table->unsignedBigInteger('quota_used')->default(0);
            $table->unsignedBigInteger('quota_total')->default(0);
            $table->bigInteger('quota_reset_at')->unsigned()->default(0);
            $table->bigInteger('last_reset_at')->unsigned()->default(0);
            $table->string('upgrade_group', 64)->default('');
            $table->string('group_before', 64)->default('');
            $table->boolean('auto_renew')->default(true);
            $table->bigInteger('cancelled_at')->unsigned()->default(0);
            $table->bigInteger('created_at')->unsigned();
            $table->bigInteger('updated_at')->unsigned();
            $table->index(['user_id', 'status'], 'idx_user_sub_active');
        });

        Schema::create('subscription_pre_consume_records', function (Blueprint $table) {
            $table->id();
            $table->unsignedInteger('user_id')->index();
            $table->unsignedInteger('subscription_id');
            $table->integer('quota');
            $table->string('request_id', 64)->default('')->index();
            $table->bigInteger('created_at')->unsigned();
            $table->boolean('consumed')->default(false);
            $table->bigInteger('consumed_at')->unsigned()->default(0);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('subscription_pre_consume_records');
        Schema::dropIfExists('user_subscriptions');
        Schema::dropIfExists('subscription_orders');
        Schema::dropIfExists('subscription_plans');
    }
};