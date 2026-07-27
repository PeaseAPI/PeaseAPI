<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('redemptions', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('user_id')->default(0)->comment('创建者 ID');
            $table->string('name', 255)->default('')->comment('名称');
            $table->string('key', 64)->unique()->comment('兑换码');
            $table->bigInteger('quota')->default(0)->comment('配额');
            $table->tinyInteger('status')->default(1)->comment('1=启用 0=禁用');
            $table->integer('max_use_count')->default(1)->comment('最大使用次数，0=无限');
            $table->integer('used_count')->default(0)->comment('已使用次数');
            $table->text('used_user_ids')->nullable()->comment('已使用用户 ID JSON 数组');
            $table->bigInteger('redeemed_at')->default(0)->comment('最近兑换时间');
            $table->bigInteger('expired_at')->default(0)->comment('过期时间戳，0=永不过期');
            $table->bigInteger('created_time')->default(0)->comment('创建时间戳');

            $table->index('user_id');
            $table->index('status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('redemptions');
    }
};