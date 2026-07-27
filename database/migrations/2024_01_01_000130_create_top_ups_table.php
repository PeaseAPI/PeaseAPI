<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('top_ups', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('user_id')->default(0)->comment('用户 ID');
            $table->bigInteger('amount')->default(0)->comment('充值额度');
            $table->decimal('money', 10, 2)->default(0)->comment('实付金额');
            $table->string('trade_no', 64)->default('')->comment('交易号');
            $table->string('trade_no_internal', 64)->default('')->comment('内部交易号');
            $table->tinyInteger('status')->default(0)->comment('0=未支付 1=已支付 2=已取消');
            $table->string('payment_method', 32)->default('')->comment('epay/stripe/creem/waffo/waffo_pancake');
            $table->string('payment_id', 255)->default('')->comment('支付网关 ID');
            $table->bigInteger('created_at')->default(0)->comment('创建时间戳');
            $table->bigInteger('updated_at')->default(0)->comment('更新时间戳');

            $table->index('user_id');
            $table->index('trade_no');
            $table->index('status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('top_ups');
    }
};