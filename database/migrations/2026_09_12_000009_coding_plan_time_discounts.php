<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Coding Plan 分时段折扣自动化（2026-09-12）
 *
 * 背景：多家官方按「计费时刻」执行不同折扣 ——
 *  - 智谱 GLM：非高峰（工作日 14:00-18:00 以外）一律 5 折；
 *  - DeepSeek：高峰（周一至五 9:00-12:00、14:00-18:00）原价，空闲时段全部减半；
 *  - 阿里云百炼：夜间 22:00-08:00 指定模型 5 折（qwen3.8-max / deepseek-v4-pro-0813 /
 *    deepseek-v4-flash-0731，模板未预置这些行，由管理员按需录入并配置窗口）。
 *
 * 此前这类折扣「不自动参与引擎计费」（docs 提示管理员手动下调系数），本迁移起改为
 * 比率行自带 time_discounts 窗口数组，引擎按计费时刻自动命中折扣：
 *   [{"name":"周末全天","days":[6,7],"start":"00:00","end":"24:00","discount":0.5}, ...]
 *  - days：ISO 周几 1-7（周一=1），缺省每天；start/end："HH:MM"，end<start 表示跨零点；
 *  - discount：消耗乘数（0.5=半价），必须位于 (0,1)；规则按顺序匹配，首条命中生效；
 *  - 未配置或未命中任何窗口 = 原价；verify-ratios 定价源 diff 同步支持该字段。
 *
 * 本迁移同时把智谱 2 行、DeepSeek 2 行官方时段窗口预置到既有「官方折算标准」行。
 */
return new class extends Migration
{
    /** 智谱官方非高峰窗口（工作日 14:00-18:00 以外 5 折） */
    public const ZHIPU_WINDOWS = [
        ['name' => '工作日非高峰(00-14点)', 'days' => [1, 2, 3, 4, 5], 'start' => '00:00', 'end' => '14:00', 'discount' => 0.5],
        ['name' => '工作日非高峰(18-24点)', 'days' => [1, 2, 3, 4, 5], 'start' => '18:00', 'end' => '24:00', 'discount' => 0.5],
        ['name' => '周末全天', 'days' => [6, 7], 'start' => '00:00', 'end' => '24:00', 'discount' => 0.5],
    ];

    /** DeepSeek 官方空闲窗口（高峰=周一至五 9:00-12:00、14:00-18:00，其余减半） */
    public const DEEPSEEK_WINDOWS = [
        ['name' => '工作日空闲(00-09点)', 'days' => [1, 2, 3, 4, 5], 'start' => '00:00', 'end' => '09:00', 'discount' => 0.5],
        ['name' => '工作日空闲(12-14点)', 'days' => [1, 2, 3, 4, 5], 'start' => '12:00', 'end' => '14:00', 'discount' => 0.5],
        ['name' => '工作日空闲(18-24点)', 'days' => [1, 2, 3, 4, 5], 'start' => '18:00', 'end' => '24:00', 'discount' => 0.5],
        ['name' => '周末全天', 'days' => [6, 7], 'start' => '00:00', 'end' => '24:00', 'discount' => 0.5],
    ];

    /** [vendor, model, match_type, windows, 时段说明] */
    public const PRESETS = [
        ['zhipu', 'glm-5.3', 'exact', self::ZHIPU_WINDOWS, '非高峰5折'],
        ['zhipu', 'glm-5.3-flash', 'exact', self::ZHIPU_WINDOWS, '非高峰5折'],
        ['deepseek', 'deepseek-flash', 'exact', self::DEEPSEEK_WINDOWS, '空闲减半'],
        ['deepseek', 'deepseek-v4-pro', 'exact', self::DEEPSEEK_WINDOWS, '空闲减半'],
    ];

    public function up(): void
    {
        if (Schema::hasTable('coding_plan_model_ratios') && ! Schema::hasColumn('coding_plan_model_ratios', 'time_discounts')) {
            Schema::table('coding_plan_model_ratios', function (Blueprint $table) {
                // 分时段折扣窗口数组 JSON（null=全时段原价）
                $table->text('time_discounts')->nullable()->after('output_rate');
            });
        }

        if (! Schema::hasTable('coding_plan_model_ratios') || ! Schema::hasColumn('coding_plan_model_ratios', 'time_discounts')) {
            return;
        }

        // 仅为仍是官方预置态的行写入窗口（管理员改过的行不覆盖 remark，避免脏写）
        $now = time();
        foreach (self::PRESETS as [$vendor, $model, $matchType, $windows, $label]) {
            $row = Schema::hasColumn('coding_plan_model_ratios', 'remark')
                ? DB::table('coding_plan_model_ratios')
                    ->where('vendor', $vendor)->where('model', $model)->where('match_type', $matchType)
                    ->where('remark', 'like', '官方折算标准%')
                    ->first()
                : null;
            if ($row === null) {
                continue;
            }
            DB::table('coding_plan_model_ratios')->where('id', $row->id)->update([
                'time_discounts' => json_encode($windows, JSON_UNESCAPED_UNICODE),
                'remark' => $row->remark.'；'.$label.'（计费时刻自动生效）',
                'updated_at' => $now,
            ]);
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('coding_plan_model_ratios') && Schema::hasColumn('coding_plan_model_ratios', 'time_discounts')) {
            foreach (self::PRESETS as [$vendor, $model, $matchType]) {
                DB::table('coding_plan_model_ratios')
                    ->where('vendor', $vendor)->where('model', $model)->where('match_type', $matchType)
                    ->whereNotNull('time_discounts')
                    ->update(['time_discounts' => null]);
            }
            Schema::table('coding_plan_model_ratios', function (Blueprint $table) {
                $table->dropColumn('time_discounts');
            });
        }
    }
};
