<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('checkins', function (Blueprint $table) {
            $table->id();
            $table->unsignedInteger('user_id')->index();
            $table->integer('day')->comment('签到天数 1-7');
            $table->integer('quota')->comment('签到奖励配额');
            $table->bigInteger('created_at')->unsigned()->index();
            $table->index(['user_id', 'created_at'], 'idx_checkins_user_date');
        });

        Schema::create('tasks', function (Blueprint $table) {
            $table->id();
            $table->string('title', 255);
            $table->string('description', 500)->default('');
            $table->string('type', 50)->comment('daily/once');
            $table->integer('quota')->default(0);
            $table->integer('limit_count')->default(0)->comment('0=不限制');
            $table->integer('sort_order')->default(0);
            $table->boolean('enabled')->default(true);
            $table->string('action', 100)->default('');
            $table->string('action_param', 500)->default('');
            $table->bigInteger('expired_at')->unsigned()->default(0)->comment('过期时间戳 0=永不过期');
            $table->bigInteger('created_at')->unsigned();
            $table->bigInteger('updated_at')->unsigned();
        });

        Schema::create('user_tasks', function (Blueprint $table) {
            $table->id();
            $table->unsignedInteger('user_id')->index();
            $table->unsignedInteger('task_id')->index();
            $table->integer('completed_count')->default(0);
            $table->bigInteger('last_completed_at')->unsigned()->default(0);
            $table->bigInteger('created_at')->unsigned();
            $table->bigInteger('updated_at')->unsigned();
            $table->index(['user_id', 'task_id'], 'idx_user_tasks_unique');
        });

        Schema::create('user_task_records', function (Blueprint $table) {
            $table->id();
            $table->unsignedInteger('user_id')->index();
            $table->unsignedInteger('task_id')->index();
            $table->integer('quota')->default(0);
            $table->bigInteger('created_at')->unsigned()->index();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('user_task_records');
        Schema::dropIfExists('user_tasks');
        Schema::dropIfExists('tasks');
        Schema::dropIfExists('checkins');
    }
};
