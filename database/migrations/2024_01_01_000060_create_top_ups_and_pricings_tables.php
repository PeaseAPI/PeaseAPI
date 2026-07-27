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
            $table->unsignedInteger('user_id')->index();
            $table->bigInteger('amount')->comment('额度');
            $table->decimal('money', 10, 6)->comment('金额(USD)');
            $table->string('trade_no', 255)->unique()->index()->comment('交易号');
            $table->string('payment_method', 50)->comment('stripe/creem/waffo/waffo_pancake/balance');
            $table->string('payment_provider', 50)->default('');
            $table->bigInteger('create_time')->unsigned();
            $table->bigInteger('complete_time')->unsigned()->nullable();
            $table->string('status', 20)->comment('pending/completed/failed/refunded');
        });

        Schema::create('pricings', function (Blueprint $table) {
            $table->string('model_name', 255)->comment('模型名');
            $table->text('description')->nullable();
            $table->string('icon', 255)->default('');
            $table->string('group', 64)->default('')->comment('用户组');
            $table->decimal('input_price', 10, 8)->nullable()->comment('输入单价(per token)');
            $table->decimal('output_price', 10, 8)->nullable()->comment('输出单价(per token)');
            $table->integer('sort_order')->default(0);
            $table->bigInteger('created_at')->unsigned();
            $table->bigInteger('updated_at')->unsigned();
            $table->primary(['model_name', 'group']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pricings');
        Schema::dropIfExists('top_ups');
    }
};