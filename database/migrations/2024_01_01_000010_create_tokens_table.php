<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tokens', function (Blueprint $table) {
            $table->id();
            $table->unsignedInteger('user_id')->index();
            $table->string('key', 128)->unique()->comment('API Key 值');
            $table->integer('status')->default(1);
            $table->string('name', 191)->index();
            $table->bigInteger('created_time')->unsigned();
            $table->bigInteger('accessed_time')->unsigned()->nullable();
            $table->bigInteger('expired_time')->default(-1)->comment('-1=永不过期');
            $table->integer('remain_quota')->default(0);
            $table->boolean('unlimited_quota')->default(false);
            $table->boolean('model_limits_enabled')->default(false);
            $table->text('model_limits')->nullable()->comment('JSON 模型限制列表');
            $table->text('allow_ips')->nullable()->comment('IP白名单, 换行分隔');
            $table->integer('used_quota')->default(0);
            $table->string('group', 64)->default('');
            $table->boolean('cross_group_retry')->default(false)->comment('跨分组重试');
            $table->timestamp('deleted_at')->nullable()->index();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tokens');
    }
};