<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Coding Plan 厂商预置目录与产品类型
 *
 * 1. coding_plan_vendors 新增 plan_kind（1=订阅制 Coding Plan / 2=按量 Token Plan），
 *    用于公开介绍页分组与管理端筛选；扣费语义仍由 billing_mode × cost_mode 决定，不受影响。
 * 2. 幂等预置国内主流厂商（默认停用 status=0，管理员启用前必须自行核对汇率与比率）：
 *    - 订阅制 Coding Plan：火山引擎 / 中国联通 / 中国移动 / 智谱 GLM
 *    - 按量 Token Plan：阿里云百炼 / 腾讯混元 / 百度千帆 / 火山方舟 / DeepSeek / Moonshot
 * 3. 附少量「停用」比率模板（主流模型族前缀，unit_cost=1 占位）——
 *    只作脚手架，绝不预置真实价格（错误价格 = 资损，须管理员核对后启用）。
 */
return new class extends Migration
{
    /** 预置厂商：code => [name, plan_kind, billing_mode, unit_name, sort] */
    public const PRESET_VENDORS = [
        'volcengine' => ['火山引擎 Coding Plan', 1, 2, '点', 10],
        'unicom' => ['中国联通 Coding Plan', 1, 2, '点', 20],
        'cmcc' => ['中国移动 Coding Plan', 1, 2, '点', 30],
        'zhipu' => ['智谱 GLM Coding Plan', 1, 2, '资源点', 40],
        'aliyun' => ['阿里云百炼 Token Plan', 2, 2, '千token', 50],
        'tencent' => ['腾讯混元 Token Plan', 2, 2, '千token', 60],
        'baidu' => ['百度智能云千帆 Token Plan', 2, 2, '千token', 70],
        'volcengine-ark' => ['火山方舟 Token Plan（按量）', 2, 2, '千token', 80],
        'deepseek' => ['DeepSeek 开放平台 Token Plan', 2, 2, '千token', 90],
        'moonshot' => ['Moonshot Kimi Token Plan', 2, 2, '千token', 100],
    ];

    /** 预置比率模板：vendor => [model 前缀, cost_mode]（unit_cost 占位 1，status=0） */
    public const PRESET_RATIOS = [
        'volcengine' => [['doubao-', 'per_request'], ['kimi-', 'per_request'], ['deepseek-', 'per_request']],
        'zhipu' => [['glm-', 'per_request']],
        'aliyun' => [['qwen-', 'per_1k_tokens'], ['deepseek-', 'per_1k_tokens']],
        'tencent' => [['hunyuan-', 'per_1k_tokens']],
        'baidu' => [['ernie-', 'per_1k_tokens'], ['deepseek-', 'per_1k_tokens']],
        'volcengine-ark' => [['doubao-', 'per_1k_tokens'], ['kimi-', 'per_1k_tokens'], ['deepseek-', 'per_1k_tokens']],
        'deepseek' => [['deepseek-', 'per_1k_tokens']],
        'moonshot' => [['kimi-', 'per_1k_tokens'], ['moonshot-', 'per_1k_tokens']],
    ];

    public function up(): void
    {
        // MySQL DDL 非事务性：按列是否已存在跳过，支持失败重放
        if (! Schema::hasColumn('coding_plan_vendors', 'plan_kind')) {
            Schema::table('coding_plan_vendors', function (Blueprint $table) {
                $table->unsignedTinyInteger('plan_kind')->default(1)->after('billing_mode');
            });
        }

        if (! Schema::hasTable('coding_plan_vendors')) {
            return;
        }

        $now = time();
        $vendorRemark = '预置厂商（默认停用）：启用前请设置 unit_exchange_rate 并核对折算比率';

        foreach (self::PRESET_VENDORS as $code => [$name, $planKind, $billingMode, $unitName, $sort]) {
            $exists = DB::table('coding_plan_vendors')->where('code', $code)->exists();
            if ($exists) {
                continue;
            }
            DB::table('coding_plan_vendors')->insert([
                'code' => $code,
                'name' => $name,
                'plan_kind' => $planKind,
                'billing_mode' => $billingMode,
                'unit_name' => $unitName,
                'unit_exchange_rate' => 1,
                'docs_url' => null,
                'pricing_source_url' => null,
                'status' => 0,
                'sort' => $sort,
                'remark' => $vendorRemark,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }

        if (! Schema::hasTable('coding_plan_model_ratios')) {
            return;
        }

        $ratioRemark = '预置模板：unit_cost 为占位值，启用前请核对价格与计费口径';
        foreach (self::PRESET_RATIOS as $vendor => $templates) {
            $vendorExists = DB::table('coding_plan_vendors')->where('code', $vendor)->exists();
            if (! $vendorExists) {
                continue;
            }
            foreach ($templates as [$model, $costMode]) {
                $exists = DB::table('coding_plan_model_ratios')
                    ->where('vendor', $vendor)
                    ->where('model', $model)
                    ->where('match_type', 'prefix')
                    ->exists();
                if ($exists) {
                    continue;
                }
                DB::table('coding_plan_model_ratios')->insert([
                    'vendor' => $vendor,
                    'model' => $model,
                    'match_type' => 'prefix',
                    'cost_mode' => $costMode,
                    'unit_cost' => 1,
                    'status' => 0,
                    'sort' => 0,
                    'remark' => $ratioRemark,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
            }
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('coding_plan_model_ratios')) {
            // 仅删除仍是预置态（停用 + 模板备注）的比率行，避免误删管理员已启用的配置
            DB::table('coding_plan_model_ratios')
                ->whereIn('vendor', array_keys(self::PRESET_RATIOS))
                ->where('status', 0)
                ->where('remark', 'like', '预置模板%')
                ->delete();
        }

        if (Schema::hasTable('coding_plan_vendors')) {
            // 仅删除未被启用的预置厂商行
            DB::table('coding_plan_vendors')
                ->whereIn('code', array_keys(self::PRESET_VENDORS))
                ->where('status', 0)
                ->where('remark', 'like', '预置厂商%')
                ->delete();
        }

        if (Schema::hasColumn('coding_plan_vendors', 'plan_kind')) {
            Schema::table('coding_plan_vendors', function (Blueprint $table) {
                $table->dropColumn('plan_kind');
            });
        }
    }
};
