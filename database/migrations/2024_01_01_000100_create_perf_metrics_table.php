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
        Schema::create('perf_metrics', function (Blueprint $table) {
            $table->id();
            $table->string('model_name', 128)->index();
            $table->string('group', 64)->index();
            $table->bigInteger('bucket_ts')->index();
            $table->bigInteger('request_count')->default(0);
            $table->bigInteger('success_count')->default(0);
            $table->bigInteger('total_latency_ms')->default(0);
            $table->bigInteger('ttft_sum_ms')->default(0);
            $table->bigInteger('ttft_count')->default(0);
            $table->bigInteger('output_tokens')->default(0);
            $table->bigInteger('generation_ms')->default(0);

            // 复合唯一索引
            $table->unique(['model_name', 'group', 'bucket_ts'], 'idx_perf_model_group_bucket');
            // 分桶时间索引
            $table->index('bucket_ts', 'idx_perf_bucket_ts');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('perf_metrics');
    }
};
