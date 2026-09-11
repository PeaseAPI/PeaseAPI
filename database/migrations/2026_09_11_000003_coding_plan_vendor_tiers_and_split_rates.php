<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Coding Plan 官方套餐档位与分段折算标准
 *
 * 1. 新表 coding_plan_vendor_tiers：各厂商官方套餐档位（个人版/团队版/坐席/用量包），
 *    公开介绍页按厂商展示（仅 status=1），管理端「套餐档位」页可增删改。
 * 2. coding_plan_model_ratios 新增 input_rate/cached_rate/output_rate（千 token 口径），
 *    并支持 cost_mode=per_token_parts 分段折算：
 *      units = (输入 token × input_rate + 缓存命中 × cached_rate + 输出 token × output_rate) / 1000
 *    对齐真实上游公式：智谱 积分 =（入×6.9 + 缓存×1.7 + 出×24）/ 10000；阿里云 Credits 分段计费。
 * 3. 幂等预置官方档位（阿里云 8 档 / 智谱 4 档 / 火山 Agent Plan 1 档）与官方分段折算标准
 *    （比率行全部停用 status=0 —— 错误价格 = 资损，管理员核对 unit_exchange_rate 后启用）：
 *      - zhipu:  glm-5.3 0.69/0.17/2.4、glm-5.3-flash 0.23/0.056/0.8（官方系数 6.9/1.7/24 等 ÷ 10）
 *      - aliyun: qwen3.6-plus 0.2/0.02/1.2（官方示例 8349→1.67 / 40794→0.82 / 573→0.69 Credits）
 */
return new class extends Migration
{
    /**
     * 官方套餐档位：vendor_code => [name, price, price_note, period, quota, quota_unit, quota_note, sort, status]
     * 来源（2026-09 官方文档）：阿里云百炼 Token Plan 个人版/团队版、智谱 GLM Coding Plan、火山方舟 Agent Plan。
     */
    public const PRESET_TIERS = [
        'aliyun' => [
            ['个人版 · Lite', 39, '原价 60 元/月', '月', 10000, 'Credits', '每 7 天限额 2,500 Credits；并发 Agent 1-2 个', 10, 1],
            ['个人版 · Standard', 139, '原价 180 元/月', '月', 40000, 'Credits', '每 7 天限额 10,000 Credits；附赠 Harness 权益', 20, 1],
            ['个人版 · Pro', 499, '原价 600 元/月', '月', null, 'Credits', '无 7 天限额（约 16× Lite 用量）；附赠 Harness 权益', 30, 1],
            ['个人版 · 用量包', 100, '需有效订阅，最多同时持有 5 个', '月/个', 20000, 'Credits', '超出套餐限额后购买补充', 40, 1],
            ['团队版 · 标准坐席', 150, '原价 198 元/座席/月', '月/座席', 25000, 'Credits', '月度总额度制，无 7 天窗口限额', 50, 1],
            ['团队版 · 高级坐席', 550, '原价 698 元/座席/月', '月/座席', 100000, 'Credits', '日常高频使用', 60, 1],
            ['团队版 · 尊享坐席', 1398, '', '月/座席', 250000, 'Credits', '重度依赖 AI 的核心开发者', 70, 1],
            ['团队版 · 共享用量包', 5000, '', '月/个', 625000, 'Credits', '跨坐席共享，优先抵扣最近到期', 80, 1],
        ],

        // 智谱 GLM Coding Plan：Lite/Pro/Max + 团队版（官网价格页为 JS 渲染，价格由管理员核对后补充）
        'zhipu' => [
            ['个人版 · Lite', null, '价格以官网购买页为准', '月', null, '资源点', '每 5 小时 2,000 / 每周 10,000 积分', 10, 1],
            ['个人版 · Pro', null, '价格以官网购买页为准', '月', null, '资源点', '每 5 小时 12,000 / 每周 60,000 积分', 20, 1],
            ['个人版 · Max', null, '价格以官网购买页为准', '月', null, '资源点', '每 5 小时 28,000 / 每周 140,000 积分', 30, 1],
            ['团队版 · 席位制', null, '价格以官网购买页为准', '月/席位', null, '资源点', '团队管理后台、席位分配与用量分析', 40, 1],
        ],

        // 火山引擎 Agent Plan：官方页为 JS 渲染无法完整抓取，仅收录可交叉验证的档位且默认不公开展示
        'volcengine' => [
            ['Agent Plan · Small', 9.9, '9.9 元/月起（待官方文档核实）', '月', null, '', '支持 Doubao / GLM / DeepSeek / Kimi / MiniMax 等模型', 10, 0],
        ],
    ];

    /** 官方分段折算标准（千 token 口径）：vendor => [[model, input_rate, cached_rate, output_rate]] */
    public const PRESET_SPLIT_RATIOS = [
        'zhipu' => [
            ['glm-5.3', 0.69, 0.17, 2.4],
            ['glm-5.3-flash', 0.23, 0.056, 0.8],
        ],
        'aliyun' => [
            ['qwen3.6-plus', 0.2, 0.02, 1.2],
        ],
    ];

    public function up(): void
    {
        if (! Schema::hasTable('coding_plan_vendor_tiers')) {
            Schema::create('coding_plan_vendor_tiers', function (Blueprint $table) {
                $table->id();
                $table->string('vendor_code', 64)->index();
                $table->string('name', 64);
                $table->decimal('price', 12, 2)->nullable();
                $table->string('price_note', 64)->nullable();
                $table->string('period', 16)->default('');
                $table->decimal('quota', 14, 2)->nullable();
                $table->string('quota_unit', 32)->default('');
                $table->string('quota_note', 128)->nullable();
                $table->unsignedTinyInteger('status')->default(1);
                $table->unsignedInteger('sort')->default(0);
                $table->string('remark', 255)->nullable();
                $table->unsignedInteger('created_at')->default(0);
                $table->unsignedInteger('updated_at')->default(0);
            });
        }

        // MySQL DDL 非事务性：逐列判断，支持失败重放
        foreach (['input_rate', 'cached_rate', 'output_rate'] as $column) {
            if (Schema::hasTable('coding_plan_model_ratios') && ! Schema::hasColumn('coding_plan_model_ratios', $column)) {
                Schema::table('coding_plan_model_ratios', function (Blueprint $table) use ($column) {
                    // 千 token 口径的输入/缓存命中/输出分段折算率（cost_mode=per_token_parts 时生效）
                    $table->decimal($column, 12, 4)->unsigned()->default(0)->after('unit_cost');
                });
            }
        }

        if (! Schema::hasTable('coding_plan_vendors')) {
            return;
        }

        $now = time();
        $tierRemark = '官方档位（2026-09 据官方文档核对）；价格/额度以官网购买页为准';

        foreach (self::PRESET_TIERS as $vendorCode => $tiers) {
            // 仅预置到已存在的厂商，避免为已删除厂商生成孤儿档位
            if (! DB::table('coding_plan_vendors')->where('code', $vendorCode)->exists()) {
                continue;
            }
            foreach ($tiers as [$name, $price, $priceNote, $period, $quota, $quotaUnit, $quotaNote, $sort, $status]) {
                $exists = DB::table('coding_plan_vendor_tiers')
                    ->where('vendor_code', $vendorCode)
                    ->where('name', $name)
                    ->exists();
                if ($exists) {
                    continue;
                }
                DB::table('coding_plan_vendor_tiers')->insert([
                    'vendor_code' => $vendorCode,
                    'name' => $name,
                    'price' => $price,
                    'price_note' => $priceNote,
                    'period' => $period,
                    'quota' => $quota,
                    'quota_unit' => $quotaUnit,
                    'quota_note' => $quotaNote,
                    'status' => $status,
                    'sort' => $sort,
                    'remark' => $tierRemark,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
            }
        }

        if (Schema::hasTable('coding_plan_model_ratios')) {
            $splitRemark = '官方折算标准（分段千token口径，来源官方文档）；启用前核对 unit_exchange_rate';
            foreach (self::PRESET_SPLIT_RATIOS as $vendorCode => $rows) {
                if (! DB::table('coding_plan_vendors')->where('code', $vendorCode)->exists()) {
                    continue;
                }
                foreach ($rows as [$model, $inputRate, $cachedRate, $outputRate]) {
                    $exists = DB::table('coding_plan_model_ratios')
                        ->where('vendor', $vendorCode)
                        ->where('model', $model)
                        ->where('match_type', 'exact')
                        ->exists();
                    if ($exists) {
                        continue;
                    }
                    DB::table('coding_plan_model_ratios')->insert([
                        'vendor' => $vendorCode,
                        'model' => $model,
                        'match_type' => 'exact',
                        'cost_mode' => 'per_token_parts',
                        // 分段模式下 unit_cost 不参与计算，保留占位值
                        'unit_cost' => 1,
                        'input_rate' => $inputRate,
                        'cached_rate' => $cachedRate,
                        'output_rate' => $outputRate,
                        'status' => 0,
                        'sort' => 0,
                        'remark' => $splitRemark,
                        'created_at' => $now,
                        'updated_at' => $now,
                    ]);
                }
            }

            // 阿里云按 Credits 统一计量（分段折算后的原生单位即 Credits），修正预置单位名（仅预置态行）
            DB::table('coding_plan_vendors')
                ->where('code', 'aliyun')
                ->where('status', 0)
                ->where('remark', 'like', '预置厂商%')
                ->where('unit_name', '千token')
                ->update(['unit_name' => 'Credits', 'updated_at' => $now]);
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('coding_plan_model_ratios')) {
            // 仅删除仍是预置态（停用 + 官方折算标准备注）的分段比率行
            DB::table('coding_plan_model_ratios')
                ->whereIn('vendor', array_keys(self::PRESET_SPLIT_RATIOS))
                ->where('status', 0)
                ->where('remark', 'like', '官方折算标准%')
                ->delete();
        }

        if (Schema::hasTable('coding_plan_vendor_tiers')) {
            DB::table('coding_plan_vendor_tiers')
                ->whereIn('vendor_code', array_keys(self::PRESET_TIERS))
                ->where('remark', 'like', '官方档位%')
                ->delete();
        }

        if (Schema::hasTable('coding_plan_vendors')) {
            DB::table('coding_plan_vendors')
                ->where('code', 'aliyun')
                ->where('status', 0)
                ->where('remark', 'like', '预置厂商%')
                ->where('unit_name', 'Credits')
                ->update(['unit_name' => '千token']);
        }

        foreach (['input_rate', 'cached_rate', 'output_rate'] as $column) {
            if (Schema::hasTable('coding_plan_model_ratios') && Schema::hasColumn('coding_plan_model_ratios', $column)) {
                Schema::table('coding_plan_model_ratios', function (Blueprint $table) use ($column) {
                    $table->dropColumn($column);
                });
            }
        }

        Schema::dropIfExists('coding_plan_vendor_tiers');
    }
};
