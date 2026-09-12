<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 工单系统（用户在线提交问题 → 管理端处理）
 * - tickets：工单主表（subject/category/priority/status，last_reply_at 供列表排序与追踪）
 * - ticket_replies：会话流水（is_admin 区分客服与用户发言，首条内容同走流水保证会话完整）
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tickets', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('user_id')->index();
            $table->string('subject', 128);
            $table->unsignedTinyInteger('category')->default(1)->comment('1=综合 2=计费 3=技术 4=功能建议');
            $table->unsignedTinyInteger('priority')->default(2)->comment('1=低 2=普通 3=高');
            $table->unsignedTinyInteger('status')->default(1)->index()->comment('1=待处理 2=已回复 3=用户追回 4=已关闭');
            $table->unsignedBigInteger('last_reply_at')->nullable();
            $table->unsignedBigInteger('created_at');
        });

        Schema::create('ticket_replies', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('ticket_id')->index();
            $table->unsignedBigInteger('user_id');
            $table->boolean('is_admin')->default(false);
            $table->text('content');
            $table->unsignedBigInteger('created_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ticket_replies');
        Schema::dropIfExists('tickets');
    }
};
