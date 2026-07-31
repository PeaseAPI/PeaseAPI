<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// 给 abilities 表添加自增 id 主键
// 原表使用复合主键 (group, model, channel_id)，但控制器代码（apiResource）
// 依赖单列自增 id 来进行 show/update/destroy 操作，且 paginate 排序也用到 id。
// 此迁移将复合主键降级为唯一索引，并添加自增 id 作为主键。
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('abilities', function (Blueprint $table) {
            // 先删除原复合主键
            $table->dropPrimary();
        });

        Schema::table('abilities', function (Blueprint $table) {
            // 添加自增 id 主键
            $table->bigIncrements('id')->first();

            // 原复合键改为唯一索引，防止重复能力记录
            $table->unique(['group', 'model', 'channel_id'], 'abilities_group_model_channel_unique');
        });
    }

    public function down(): void
    {
        Schema::table('abilities', function (Blueprint $table) {
            $table->dropUnique('abilities_group_model_channel_unique');
            $table->dropColumn('id');
        });

        Schema::table('abilities', function (Blueprint $table) {
            $table->primary(['group', 'model', 'channel_id']);
        });
    }
};