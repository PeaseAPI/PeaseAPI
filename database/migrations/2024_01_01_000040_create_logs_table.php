<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('logs', function (Blueprint $table) {
            $table->id();
            $table->unsignedInteger('user_id')->index();
            $table->bigInteger('created_at')->unsigned()->index();
            $table->integer('type')->default(0)->comment('0=未知 1=充值 2=消费 3=管理 4=系统 5=错误 6=退款 7=登录');
            $table->text('content')->nullable();
            $table->string('username', 191)->index();
            $table->string('token_name', 191)->index();
            $table->string('model_name', 191)->index();
            $table->integer('quota')->default(0);
            $table->integer('prompt_tokens')->default(0);
            $table->integer('completion_tokens')->default(0);
            $table->integer('use_time')->default(0)->comment('耗时(ms)');
            $table->boolean('is_stream')->default(false);
            $table->unsignedInteger('channel_id')->default(0)->index();
            $table->string('channel_name', 191)->default('');
            $table->unsignedInteger('token_id')->default(0)->index();
            $table->string('group', 64)->index();
            $table->string('ip', 64)->index();
            $table->string('request_id', 64)->default('')->index();
            $table->string('upstream_request_id', 128)->default('')->index();
            $table->text('other')->nullable();
            $table->index(['user_id', 'id'], 'idx_logs_user_id_id');
            $table->index(['created_at', 'type'], 'idx_logs_created_at_type');
            $table->index(['username', 'model_name'], 'idx_logs_username_model');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('logs');
    }
};
