<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('options', function (Blueprint $table) {
            $table->string('key', 191)->primary();
            $table->text('value')->nullable();
        });

        Schema::create('redemptions', function (Blueprint $table) {
            $table->id();
            $table->unsignedInteger('user_id')->index();
            $table->char('key', 32)->unique()->comment('兑换码');
            $table->integer('status')->default(1)->comment('1=未使用 2=已使用');
            $table->integer('quota')->default(100);
            $table->string('reward_group', 64)->default('')->comment('兑换后升级的用户组');
            $table->string('name', 191)->default('');
            $table->bigInteger('created_time')->unsigned();
            $table->bigInteger('used_time')->unsigned()->default(0);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('redemptions');
        Schema::dropIfExists('options');
    }
};