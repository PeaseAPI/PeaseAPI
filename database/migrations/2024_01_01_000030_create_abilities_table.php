<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('abilities', function (Blueprint $table) {
            $table->string('group', 64)->comment('用户组');
            $table->string('model', 255)->comment('模型名');
            $table->unsignedInteger('channel_id')->index()->comment('渠道ID');
            $table->boolean('enabled')->default(true);
            $table->bigInteger('priority')->default(0)->index();
            $table->unsignedInteger('weight')->default(0)->index();
            $table->string('tag', 191)->nullable()->index();
            $table->primary(['group', 'model', 'channel_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('abilities');
    }
};