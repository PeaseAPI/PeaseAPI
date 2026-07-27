<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('midjourneys', function (Blueprint $table) {
            $table->id();
            $table->integer('code')->default(0);
            $table->integer('user_id')->index();
            $table->string('action', 40)->index();
            $table->string('mj_id')->index();
            $table->text('prompt')->nullable();
            $table->text('prompt_en')->nullable();
            $table->text('description')->nullable();
            $table->string('state', 255)->nullable();
            $table->bigInteger('submit_time')->index();
            $table->bigInteger('start_time')->index();
            $table->bigInteger('finish_time')->index();
            $table->text('image_url')->nullable();
            $table->text('video_url')->nullable();
            $table->text('video_urls')->nullable();
            $table->string('status', 20)->index();
            $table->string('progress', 30)->index();
            $table->text('fail_reason')->nullable();
            $table->integer('channel_id');
            $table->integer('quota')->default(0);
            $table->text('buttons')->nullable();
            $table->text('properties')->nullable();

            $table->index(['user_id', 'mj_id']);
            $table->index(['channel_id', 'status']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('midjourneys');
    }
};