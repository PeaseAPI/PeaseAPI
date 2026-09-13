<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * P3-1 + P3-5 比率预置补录：
 *
 * 1. P3-1 阿里云夜间五折三行（vendor=aliyun，官方三率未公布）：qwen3.8-max /
 *    deepseek-v4-pro-0813 / deepseek-v4-flash-0731，rates 为占位 0（启用前必须补全），
 *    time_discounts 预置 22:00→08:00 跨零点窗口（end<start 写法，
 *    CodingPlanModelRatio::timeDiscountAt() 原生支持），对应活动行见迁移 000015。
 * 2. P3-5 新厂商预置：
 *    - minimax（全新厂商行，国际站 minimax.io USD 价，官方源需代理）：3 模型 = 解析器
 *      快照 JSON（2026-09-12-0347）镜像，M3 >512k 分档取首档。
 *    - siliconflow（全新厂商行，¥/百万 tokens 双时段价组）：解析器快照仅目录 48 模型
 *      （entries=0，按量价未成结构化输出），比率行按快照笔记 §8 样例预置 14 行
 *      （模型名与目录一致=小写 org/model；双时段价映射 time_discounts；分段价取首档），
 *      全量价目待 parser 升级后经 sync-official diff 增补。
 * 3. Kimi K3 官方价核对：快照 §9 确认 K3 已发布但价目表 SPA 未取到数值，现库
 *    moonshot 预置行（kimi-k3 等）维持不变，待价目表可抓后 diff 增补（P3-5 备注项）。
 *
 * 幂等：厂商行 exists 跳过；比率行 vendor+model+cost_mode 存在跳过（绝不覆盖人工行）。
 * down 用 remark 前缀识别删除，厂商行仅删 status=0。
 */

return new class extends Migration
{
    /** 预置厂商 remark：code => remark（其余字段见 up()） */
    public const PRESET_VENDOR_REMARKS = [
        'minimax' => '官方价目参照预置（快照 2026-09-12，国际站 minimax.io USD 价；国内 minimaxi.com 为 ¥ 不配）；官方源需代理（PEASE_API_HTTP_PROXY）；M3 >512k 上下文分档取首档；Priority Tab 1.5x 条件价与 Legacy Accordion 整段剥离；启用前请核对',
        'siliconflow' => '官方价目参照预置（快照 2026-09-12，¥/百万 tokens 双时段价组）；双时段「9点～18点」价组可映射 time_discounts；Pro/ 前缀=加速标记；bge 系列 embedding/reranker 免费；全量按量价解析待 parser 升级（当前快照仅目录 48 模型 + 样例价）；启用前请核对',
    ];

    /** 预置比率：[vendor, model, input_rate, cached_rate, output_rate, time_discounts(JSON|null), remark] */
    public const PRESET_RATIOS = [
        ['aliyun', 'qwen3.8-max', 0, 0, 0, '[{"name":"夜间五折(22:00-08:00)","days":[1,2,3,4,5,6,7],"start":"22:00","end":"08:00","discount":0.5}]', '官方三率未公布（rates 为占位 0，启用前必须补全三率）；夜间 22:00–08:00 跨零点五折窗口已预置（end<start 跨零点写法，timeDiscountAt 原生支持），对应活动行见 coding_plan_promotions（迁移 000015）'],
        ['aliyun', 'deepseek-v4-pro-0813', 0, 0, 0, '[{"name":"夜间五折(22:00-08:00)","days":[1,2,3,4,5,6,7],"start":"22:00","end":"08:00","discount":0.5}]', '官方三率未公布（rates 为占位 0，启用前必须补全三率）；夜间 22:00–08:00 跨零点五折窗口已预置（跨零点写法），对应活动行见 coding_plan_promotions（迁移 000015）'],
        ['aliyun', 'deepseek-v4-flash-0731', 0, 0, 0, '[{"name":"夜间五折(22:00-08:00)","days":[1,2,3,4,5,6,7],"start":"22:00","end":"08:00","discount":0.5}]', '官方三率未公布（rates 为占位 0，启用前必须补全三率）；夜间 22:00–08:00 跨零点五折窗口已预置（跨零点写法），对应活动行见 coding_plan_promotions（迁移 000015）'],
        ['minimax', 'minimax-m3', 0.0003, 0.00006, 0.0012, null, '官方折算标准（模板 v2026-09-12 官方页快照，官方 USD/百万 tokens ÷1000 存 /1k，启用前请核对）；M3 >512k 上下文为更高分档价，取首档预置'],
        ['minimax', 'minimax-m2.7', 0.0003, 0.00006, 0.0012, null, '官方折算标准（模板 v2026-09-12 官方页快照，官方 USD/百万 tokens ÷1000 存 /1k，启用前请核对）'],
        ['minimax', 'minimax-m2.7-highspeed', 0.0006, 0.00006, 0.0024, null, '官方折算标准（模板 v2026-09-12 官方页快照，官方 USD/百万 tokens ÷1000 存 /1k，启用前请核对）；highspeed 加速版'],
        ['siliconflow', 'tencent/hunyuan-a13b-instruct', 0.001, 0, 0.004, '[{"name":"非高峰(00-09点)","days":[1,2,3,4,5,6,7],"start":"00:00","end":"09:00","discount":0.8},{"name":"非高峰(18-24点)","days":[1,2,3,4,5,6,7],"start":"18:00","end":"24:00","discount":0.8}]', '官方折算标准（模板 v2026-09-12 官方页快照，官方 ¥/百万 tokens ÷1000 存 /1k，启用前请核对）；主行=高峰价（9–18 点 1.00/4.00），非高峰（0–9、18–24 点 0.80/3.20）以 time_discounts 八折自动生效；官方未列缓存读价'],
        ['siliconflow', 'deepseek-ai/deepseek-v4-flash', 0.003, 0.0003, 0.009, '[{"name":"空闲(02-08点)","days":[1,2,3,4,5,6,7],"start":"02:00","end":"08:00","discount":0.5}]', '官方折算标准（模板 v2026-09-12 官方页快照，官方 ¥/百万 tokens ÷1000 存 /1k，启用前请核对）；主行=常规价（3.00/0.30/9.00），02–8 点（1.50/0.15/4.50）以 time_discounts 五折自动生效'],
        ['siliconflow', 'tencent/hy4-preview', 0.006, 0.0003, 0.018, null, '官方折算标准（模板 v2026-09-12 官方页快照，官方 ¥/百万 tokens ÷1000 存 /1k，启用前请核对）'],
        ['siliconflow', 'zai-org/glm-5.3', 0.008, 0.002, 0.028, null, '官方折算标准（模板 v2026-09-12 官方页快照，官方 ¥/百万 tokens ÷1000 存 /1k，启用前请核对）'],
        ['siliconflow', 'zai-org/glm-5.2', 0.008, 0.002, 0.028, null, '官方折算标准（模板 v2026-09-12 官方页快照，官方 ¥/百万 tokens ÷1000 存 /1k，启用前请核对）'],
        ['siliconflow', 'zai-org/glm-5.1', 0.006, 0.0013, 0.024, null, '官方折算标准（模板 v2026-09-12 官方页快照，官方 ¥/百万 tokens ÷1000 存 /1k，启用前请核对）；Pro 加速版为上下文分段价 [0,32k) 6/24/1.3、[32k,∞) 8/28/2，取首档预置'],
        ['siliconflow', 'deepseek-ai/deepseek-v4-pro', 0.012, 0.001, 0.024, null, '官方折算标准（模板 v2026-09-12 官方页快照，官方 ¥/百万 tokens ÷1000 存 /1k，启用前请核对）'],
        ['siliconflow', 'deepseek-ai/deepseek-v3.2', 0.004, 0.0004, 0.006, null, '官方折算标准（模板 v2026-09-12 官方页快照，官方 ¥/百万 tokens ÷1000 存 /1k，启用前请核对）'],
        ['siliconflow', 'meituan-longcat/longcat-2.0', 0.005, 0.0001, 0.02, null, '官方折算标准（模板 v2026-09-12 官方页快照，官方 ¥/百万 tokens ÷1000 存 /1k，启用前请核对）'],
        ['siliconflow', 'moonshotai/kimi-k2.7-code', 0.0065, 0.0013, 0.027, null, '官方折算标准（模板 v2026-09-12 官方页快照，官方 ¥/百万 tokens ÷1000 存 /1k，启用前请核对）'],
        ['siliconflow', 'moonshotai/kimi-k2.6', 0.0065, 0.0011, 0.027, null, '官方折算标准（模板 v2026-09-12 官方页快照，官方 ¥/百万 tokens ÷1000 存 /1k，启用前请核对）；Pro 加速版'],
        ['siliconflow', 'qwen/qwen3.8-27b', 0.003, 0, 0.012, null, '官方折算标准（模板 v2026-09-12 官方页快照，官方 ¥/百万 tokens ÷1000 存 /1k，启用前请核对）；官方未列缓存读价'],
        ['siliconflow', 'qwen/qwen3.5-122b-a10b', 0.0008, 0, 0.0064, null, '官方折算标准（模板 v2026-09-12 官方页快照，官方 ¥/百万 tokens ÷1000 存 /1k，启用前请核对）；上下文分段价 [0,128k) 0.8/6.4、[128k,∞) 2/16，取首档预置；官方未列缓存读价'],
        ['siliconflow', 'qwen/qwen3.6-35b-a3b', 0.0018, 0, 0.0108, null, '官方折算标准（模板 v2026-09-12 官方页快照，官方 ¥/百万 tokens ÷1000 存 /1k，启用前请核对）；官方未列缓存读价'],
    ];

    public function up(): void
    {
        if (Schema::hasTable('coding_plan_vendors')) {
            $vendorFields = [
                'minimax' => ['MiniMax Token Plan（按量）', 'USD', 'https://platform.minimax.io/docs/guides/pricing-paygo.md', 'https://platform.minimax.io/docs', 108],
                'siliconflow' => ['SiliconFlow 硅基流动 Token Plan（按量）', 'CNY', 'https://siliconflow.cn/pricing', 'https://docs.siliconflow.cn', 109],
            ];
            foreach ($vendorFields as $code => [$name, $currency, $pricing, $docs, $sort]) {
                if (DB::table('coding_plan_vendors')->where('code', $code)->exists()) {
                    continue;
                }
                $now = time();
                DB::table('coding_plan_vendors')->insert([
                    'code' => $code,
                    'name' => $name,
                    'billing_mode' => 2,
                    'plan_kind' => 2,
                    'unit_name' => '千token',
                    'currency' => $currency,
                    'docs_url' => $docs,
                    'pricing_source_url' => $pricing,
                    'status' => 0,
                    'sort' => $sort,
                    'remark' => self::PRESET_VENDOR_REMARKS[$code],
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
            }
        }

        if (Schema::hasTable('coding_plan_model_ratios')) {
            $now = time();
            foreach (self::PRESET_RATIOS as [$vendor, $model, $input, $cached, $output, $discounts, $remark]) {
                $exists = DB::table('coding_plan_model_ratios')->where([
                    'vendor' => $vendor,
                    'model' => $model,
                    'cost_mode' => 'per_token_parts',
                ])->exists();
                if ($exists) {
                    continue;
                }
                DB::table('coding_plan_model_ratios')->insert([
                    'vendor' => $vendor,
                    'model' => $model,
                    'match_type' => 'exact',
                    'cost_mode' => 'per_token_parts',
                    'unit_cost' => 1,
                    'input_rate' => $input,
                    'cached_rate' => $cached,
                    'output_rate' => $output,
                    'time_discounts' => $discounts,
                    'status' => 0,
                    'sort' => 0,
                    'remark' => $remark,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
            }
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('coding_plan_model_ratios')) {
            DB::table('coding_plan_model_ratios')
                ->whereIn('vendor', ['aliyun', 'minimax', 'siliconflow'])
                ->where('match_type', 'exact')
                ->where(function ($q) {
                    $q->where('remark', 'like', '官方三率未公布%')
                        ->orWhere('remark', 'like', '官方折算标准（模板 v2026-09-12%');
                })
                ->delete();
        }
        if (Schema::hasTable('coding_plan_vendors')) {
            DB::table('coding_plan_vendors')
                ->whereIn('code', array_keys(self::PRESET_VENDOR_REMARKS))
                ->where('status', 0)
                ->delete();
        }
    }
};
