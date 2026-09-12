<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * P9-3 恢复回归：冷却恢复点。
 * - coding_plan_accounts.cooldown_until：账号级冷却（上游 Retry-After/重置文案解析出的精准恢复点；0=无冷却）
 *   —— 兜底：无窗口信息（quota_* 全 0 或上游未告知）时按保守默认 5h 窗口设置，
 *   修复「无周期配额限制的账号被上游 429 打标耗尽后 reset_*_at 恒为 0 → 永不恢复」缺口
 * - channels.cooldown_until：渠道级冷却（账号池全部不可用时 = 池内最早恢复点；任一账号可用 = 0）
 *   —— 供 cost_first 调度（candidateChannels）与 failover 候选过滤，恢复到点自动切回低成本源；
 *   static 策略 pickChannel SQL 保持原样（P9-1 承诺），由 relay 层 failover 兜底
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('coding_plan_accounts') && ! Schema::hasColumn('coding_plan_accounts', 'cooldown_until')) {
            Schema::table('coding_plan_accounts', function (Blueprint $table) {
                $table->unsignedInteger('cooldown_until')->default(0)->after('reset_monthly_at')->comment('账号级冷却恢复点（上游 Retry-After/文案解析；0=无）');
            });
        }

        if (Schema::hasTable('channels') && ! Schema::hasColumn('channels', 'cooldown_until')) {
            Schema::table('channels', function (Blueprint $table) {
                $table->unsignedInteger('cooldown_until')->default(0)->after('remark')->comment('渠道级冷却恢复点（账号池全不可用时=最早恢复点；0=无）');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('coding_plan_accounts') && Schema::hasColumn('coding_plan_accounts', 'cooldown_until')) {
            Schema::table('coding_plan_accounts', function (Blueprint $table) {
                $table->dropColumn('cooldown_until');
            });
        }

        if (Schema::hasTable('channels') && Schema::hasColumn('channels', 'cooldown_until')) {
            Schema::table('channels', function (Blueprint $table) {
                $table->dropColumn('cooldown_until');
            });
        }
    }
};
