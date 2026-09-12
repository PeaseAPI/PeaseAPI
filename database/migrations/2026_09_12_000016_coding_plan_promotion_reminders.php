<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * P2-3：Coding Plan 活动提醒流水表（防重发）。
 *
 * - promotion_id + kind + user_id 唯一：同活动同类型对同一对象只发一次。
 * - kind：ending=即将到期提醒（remaining <= remind_days×86400），
 *         expired=已过期自动处理流水（status 置 2 后记一条，不再通知）。
 * - user_id=0 表示站内公告（Option Notice 追加段落，全局一份）；
 *   user_id>0 表示该用户的邮件提醒（PromotionReminderMail 队列发送）。
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('coding_plan_promotion_reminders')) {
            return;
        }

        Schema::create('coding_plan_promotion_reminders', function (Blueprint $table) {
            $table->id();
            $table->unsignedInteger('promotion_id')->index();
            $table->string('kind', 16)->comment('ending/expired');
            $table->unsignedInteger('user_id')->default(0)->comment('0=站内公告全局，>0=该用户邮件');
            $table->unsignedInteger('created_at')->default(0);
            $table->unique(['promotion_id', 'kind', 'user_id'], 'promo_reminder_dedupe');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('coding_plan_promotion_reminders');
    }
};
