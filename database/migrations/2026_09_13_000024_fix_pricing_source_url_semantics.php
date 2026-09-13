<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * P3-6b 收尾：pricing_source_url 语义修正（第 7 个真 bug，QA-26 复检发现）。
 *
 * 字段语义（第 6 bug 修复时固化，迁移 000020 期间核对）：coding_plan_vendors.
 * pricing_source_url = verify-ratios diff 拉取的「结构化 JSON 端点」（千帆模式）；
 * HTML/Markdown 定价页应配在 sync 注册表（CodingPlanOfficialSourceService），
 * 由快照管线解析，verify 对此类厂商显示「未配置定价源」。
 *
 * 第 6 bug（xai/minimax/cmcc 误存 HTML URL）已修并固化为部署步骤；本轮复查发现
 * 000021 创建 siliconflow 厂商行时同样误填（HTML 792KB 页），且 openai-token（.md 页）、
 * google-token（HTML 页）同病——openai/google 因真实环境 TLS 403 持续失败而掩盖了
 * 语义错误（失败状态与 403 无法区分）。verify 对 siliconflow 每次 normalizeStructured
 * Entries 必失败 → source_status=3 连续累积告警（假告警，QA-4 同类：结构性失败污染）。
 *
 * 修正：三家 pricing_source_url 全部置 NULL（真实语义=无 JSON 端点）。
 * down 恢复原值（可逆留痕）。
 */

return new class extends Migration
{
    /** [code => 原误存值]（down 恢复用） */
    public const WRONG_URLS = [
        'openai-token' => 'https://platform.openai.com/docs/pricing.md',
        'google-token' => 'https://ai.google.dev/gemini-api/docs/pricing',
        'siliconflow' => 'https://siliconflow.cn/pricing',
    ];

    public function up(): void
    {
        if (! Schema::hasTable('coding_plan_vendors')) {
            return;
        }

        DB::table('coding_plan_vendors')
            ->whereIn('code', array_keys(self::WRONG_URLS))
            ->update(['pricing_source_url' => null, 'updated_at' => time()]);
    }

    public function down(): void
    {
        if (! Schema::hasTable('coding_plan_vendors')) {
            return;
        }

        foreach (self::WRONG_URLS as $code => $url) {
            DB::table('coding_plan_vendors')->where('code', $code)
                ->update(['pricing_source_url' => $url, 'updated_at' => time()]);
        }
    }
};
