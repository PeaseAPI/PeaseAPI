<?php

use Carbon\Carbon;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * P2-2：预置已知厂商活动（限时价/时段折扣/模型退市），均注官方来源 URL。
 *
 * 数据来源（2026-09-12 核对）：
 * - 智谱：docs.bigmodel.cn/cn/coding-plan/overview（夜间畅用，每日 23:00–次日 09:00）
 * - 阿里云：docs.bailian.console.aliyun.com/llms-full.txt（个人版限时价；夜间五折）
 * - 火山方舟：volcengine.com/docs/82379/2658332（Auto 活动系数 0.5，至 2026-11-08）
 * - 腾讯：cloud.tencent.com/document/product/1823/130060（GLM-5/5.1 2026-10-09 下线）
 * - Google：ai.google.dev/gemini-api/docs/pricing（促销价至 2026-12-31，2027-01-01 恢复原价）
 * - OpenAI：platform.openai.com/docs/pricing.md（gpt-5.6-sol 促销价至少至 2026-11-21）
 * - xAI：docs.x.ai/developers/models.md（Imagine 图像相关 2026-11-02 退役）
 * - 移动：ecloud.10086.cn/op-help-center/doc/article/98322（首订特价至 2026-12-31）
 *
 * 幂等约定：vendor+title firstOrCreate（不覆盖管理员改过的行）；remark 统一
 * 「官方活动（预置 2026-09-12）（P2-2）」标记，down 按此清除。每日循环型活动
 * （智谱夜间畅用/阿里云夜间五折）无总截止 → ends_at=null；starts_at=0 表示未公布开始。
 */
return new class extends Migration
{
    /** [vendor, kind, title, description, discount, ends_at(北京时刻|null), sort, source_url] */
    private const PROMOTIONS = [
        ['zhipu', 'discount', '夜间畅用（每日 23:00–次日 09:00）', '每日 23:00–次日 09:00：ZCode 端 GLM-5.3-Flash 畅用不限量、其他 Agent 额度翻倍；每日循环窗口，官方未公布总截止。', null, null, 10, 'https://docs.bigmodel.cn/cn/coding-plan/overview'],
        ['aliyun', 'price_change', 'Token Plan 个人版限时价', '个人版 Lite 39（原价 60）/ Standard 139（原价 180）/ Pro 499（原价 600）元/月；官方未公布截止时间。', null, null, 10, 'https://docs.bailian.console.aliyun.com/llms-full.txt'],
        ['aliyun', 'discount', '夜间五折（每日 22:00–次日 08:00）', '每晚 22:00–次日 08:00 调用 qwen3.8-max、deepseek-v4-pro-0813、deepseek-v4-flash-0731 五折；如需跟投可由管理员下调对应模型系数。', 0.5, null, 20, 'https://docs.bailian.console.aliyun.com/llms-full.txt'],
        ['volcengine', 'discount', 'Auto 模式活动系数 0.5', 'Agent Plan Auto 路由活动系数 0.5（夜间大幅路由 kimi-k3），至 2026-11-08；glm-5.3-flash 折扣活动等以官方公告为准。', 0.5, '2026-11-08 23:59:59', 10, 'https://www.volcengine.com/docs/82379/2658332'],
        ['tencent', 'model_retirement', 'GLM-5 / GLM-5.1 下线', 'TokenHub 的 GLM-5、GLM-5.1 于 2026-10-09 下线，请提前迁移到替代模型。', null, '2026-10-09 23:59:59', 10, 'https://cloud.tencent.com/document/product/1823/130060'],
        ['google', 'discount', 'Gemini API 促销价', 'Gemini API 促销价持续至 2026-12-31，2027-01-01 起恢复原价（恢复价已按长期价录入模型价格）。', null, '2026-12-31 23:59:59', 10, 'https://ai.google.dev/gemini-api/docs/pricing'],
        ['openai', 'discount', 'gpt-5.6-sol 促销价', 'gpt-5.6-sol 促销价至少持续至 2026-11-21（官方可能延长，到期前请核对最新公告）。', null, '2026-11-21 23:59:59', 10, 'https://platform.openai.com/docs/pricing.md'],
        ['xai', 'model_retirement', 'Imagine 图像相关退役', 'Imagine 图像相关模型于 2026-11-02 退役，请提前迁移。', null, '2026-11-02 23:59:59', 10, 'https://docs.x.ai/developers/models.md'],
        ['cmcc', 'discount', '首订特价（至 2026-12-31）', 'Coding Plan 首订 Lite 7.9 元 / Pro 39.9 元（原价 40/200），续订 5 折券 1 次；活动至 2026-12-31。', null, '2026-12-31 23:59:59', 10, 'https://ecloud.10086.cn/op-help-center/doc/article/98322'],
    ];

    private const REMARK = '官方活动（预置 2026-09-12）（P2-2）';

    public function up(): void
    {
        if (! Schema::hasTable('coding_plan_promotions')) {
            return;
        }

        foreach (self::PROMOTIONS as [$vendor, $kind, $title, $description, $discount, $endsAt, $sort, $sourceUrl]) {
            // 幂等：vendor+title 已存在即跳过（不覆盖管理员改过的行）
            if (DB::table('coding_plan_promotions')->where('vendor', $vendor)->where('title', $title)->exists()) {
                continue;
            }

            DB::table('coding_plan_promotions')->insert([
                'vendor' => $vendor,
                'kind' => $kind,
                'title' => $title,
                'description' => $description,
                'discount' => $discount,
                'starts_at' => 0, // 官方未公布开始
                'ends_at' => $endsAt !== null ? Carbon::parse($endsAt, 'Asia/Shanghai')->getTimestamp() : null,
                'source_url' => $sourceUrl,
                'status' => 1,
                'remind_days' => 7,
                'sort' => $sort,
                'remark' => self::REMARK,
                'created_at' => time(),
                'updated_at' => time(),
            ]);
        }
    }

    public function down(): void
    {
        if (! Schema::hasTable('coding_plan_promotions')) {
            return;
        }

        // 仅删除本次预置的行（remark 标记），管理员自建/改过的行不受影响
        DB::table('coding_plan_promotions')->where('remark', self::REMARK)->delete();
    }
};
