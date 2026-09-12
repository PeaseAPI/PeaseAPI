<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Token↔Ability 多对多 pivot 表（Token::abilities belongsToMany 引用，
     * 此前缺失建表迁移导致 /web-api/tokens 预加载 abilities 时 SQL 报错）。
     */
    public function up(): void
    {
        Schema::create('token_abilities', function (Blueprint $table) {
            $table->unsignedBigInteger('token_id')->comment('令牌ID');
            $table->unsignedBigInteger('ability_id')->comment('能力ID（abilities.id）');
            $table->primary(['token_id', 'ability_id']);
            $table->index('ability_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('token_abilities');
    }
};
