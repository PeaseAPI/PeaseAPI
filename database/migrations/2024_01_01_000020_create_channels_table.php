<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('channels', function (Blueprint $table) {
            $table->id();
            $table->integer('type')->default(0)->comment('渠道类型ID');
            $table->text('key')->comment('API Key');
            $table->string('openai_organization', 255)->nullable();
            $table->string('test_model', 255)->nullable();
            $table->integer('status')->default(1);
            $table->string('name', 191)->index();
            $table->unsignedInteger('weight')->default(0);
            $table->bigInteger('created_time')->unsigned();
            $table->bigInteger('test_time')->unsigned()->nullable();
            $table->integer('response_time')->nullable()->comment('毫秒');
            $table->string('base_url', 255)->default('')->comment('自定义URL');
            $table->text('other')->nullable()->comment('额外的JSON配置');
            $table->decimal('balance', 10, 4)->default(0)->comment('USD余额');
            $table->bigInteger('balance_updated_time')->unsigned()->nullable();
            $table->text('models')->nullable()->comment('支持的模型列表');
            $table->string('group', 64)->default('default');
            $table->unsignedBigInteger('used_quota')->default(0);
            $table->text('model_mapping')->nullable()->comment('模型映射JSON');
            $table->string('status_code_mapping', 1024)->default('')->comment('状态码映射JSON');
            $table->bigInteger('priority')->default(0);
            $table->integer('auto_ban')->default(1)->comment('是否自动封禁');
            $table->text('other_info')->nullable();
            $table->string('tag', 191)->nullable()->index();
            $table->text('setting')->nullable()->comment('渠道额外设置JSON');
            $table->text('param_override')->nullable()->comment('参数覆盖JSON');
            $table->text('header_override')->nullable()->comment('头部覆盖JSON');
            $table->string('remark', 255)->default('');
            $table->json('channel_info')->nullable()->comment('渠道信息(多Key等)');
            $table->text('settings')->nullable()->comment('其他设置(Azure版本等)');
            $table->index(['priority'], 'idx_channels_priority');
            $table->index(['type', 'status'], 'idx_channels_type_status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('channels');
    }
};
