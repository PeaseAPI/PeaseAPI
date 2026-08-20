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
            $table->string('name', 100)->default('');
            $table->string('description', 500)->default('');
            $table->decimal('price', 10, 2)->default(0);
            $table->string('currency', 10)->default('CNY');
            $table->bigInteger('quota')->default(0);
            $table->integer('duration')->default(1)->comment('时长数值');
            $table->string('duration_unit', 10)->default('month')->comment('day/month/year');
            $table->string('reset_period', 20)->default('none')->comment('none/daily/weekly/monthly');
            $table->json('features')->nullable();
            $table->tinyInteger('status')->default(1)->comment('1=启用 0=禁用');
            $table->string('stripe_price_id', 255)->default('');
            $table->string('creem_product_id', 255)->default('');
            $table->string('waffo_product_id', 255)->default('');
            $table->integer('sort')->default(0);
            $table->bigInteger('created_at')->default(0);
            $table->bigInteger('updated_at')->default(0);
        });

        Schema::create('subscriptions', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('user_id')->default(0);
            $table->unsignedBigInteger('plan_id')->default(0);
            $table->tinyInteger('status')->default(1)->comment('1=有效 0=失效 2=过期');
            $table->bigInteger('period_start')->default(0);
            $table->bigInteger('period_end')->default(0);
            $table->bigInteger('quota')->default(0)->comment('本期配额');
            $table->bigInteger('used_quota')->default(0)->comment('已用配额');
            $table->string('payment_method', 32)->default('');
            $table->string('trade_no', 64)->default('');
            $table->tinyInteger('auto_renew')->default(0);
            $table->bigInteger('created_at')->default(0);
            $table->bigInteger('updated_at')->default(0);

            $table->index('user_id');
            $table->index('plan_id');
            $table->index('status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('subscriptions');
        Schema::dropIfExists('subscription_plans');
    }
};
