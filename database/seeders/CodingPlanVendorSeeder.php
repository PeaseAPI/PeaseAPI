<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\CodingPlanModelRatio;
use App\Models\CodingPlanVendor;
use Illuminate\Database\Seeder;

/**
 * Coding Plan 供应商元配置 + 默认模型折算比率
 *
 * 说明：
 * - 各厂商原生计费单位不同（Anthropic/Codex 按请求提交，Qwen 按千 token），
 *   比率表负责把「原生单位」折算为平台「积分」（再乘以账号/供应商汇率）。
 * - 以下 unit_cost 为预置默认值，管理员可在后台「模型折算比率」中随时调整。
 */
class CodingPlanVendorSeeder extends Seeder
{
    public function run(): void
    {
        $now = time();

        $vendors = [
            [
                'code' => 'anthropic',
                'name' => 'Anthropic (Claude Code)',
                'docs_url' => 'https://api.anthropic.com',
                'unit_name' => '次',
                'unit_exchange_rate' => 1.0, // 1 次提交 = 1 积分
                'sort' => 1,
                'status' => 1,
                'remark' => 'Claude Max 订阅账号池，按请求提交计费',
            ],
            [
                'code' => 'openai',
                'name' => 'OpenAI (Codex)',
                'docs_url' => 'https://api.openai.com/v1',
                'unit_name' => '次',
                'unit_exchange_rate' => 1.0,
                'sort' => 2,
                'status' => 1,
                'remark' => 'Codex 订阅账号池，按请求提交计费',
            ],
            [
                'code' => 'google',
                'name' => 'Google (Gemini CLI)',
                'docs_url' => 'https://generativelanguage.googleapis.com',
                'unit_name' => '次',
                'unit_exchange_rate' => 1.0,
                'sort' => 3,
                'status' => 1,
                'remark' => 'Gemini 订阅账号池，按请求提交计费',
            ],
            [
                'code' => 'alibaba',
                'name' => 'Alibaba (Qwen Code)',
                'docs_url' => 'https://dashscope.aliyuncs.com/compatible-mode/v1',
                'unit_name' => '点',
                'unit_exchange_rate' => 1.0, // 1 点(千 token 折算后) = 1 积分，可按需调整
                'sort' => 4,
                'status' => 1,
                'remark' => '通义灵码/Qwen API，按千 token 计费（见比率表）',
            ],
        ];

        foreach ($vendors as $v) {
            CodingPlanVendor::query()->updateOrCreate(
                ['code' => $v['code']],
                $v + ['created_at' => $now, 'updated_at' => $now]
            );
        }

        // 每个厂商一条前缀兜底规则（exact 优先于 prefix，最长前缀优先于短前缀）
        $ratios = [
            // Anthropic：按请求提交，1 次提交 = 1 原生单位
            ['vendor' => 'anthropic', 'model' => 'claude-', 'match_type' => CodingPlanModelRatio::MATCH_PREFIX, 'unit_cost' => 1.0, 'cost_mode' => CodingPlanModelRatio::COST_PER_REQUEST, 'remark' => 'Claude 全系默认：1 请求 = 1 单位'],
            // OpenAI Codex：按请求提交
            ['vendor' => 'openai', 'model' => 'codex-', 'match_type' => CodingPlanModelRatio::MATCH_PREFIX, 'unit_cost' => 1.0, 'cost_mode' => CodingPlanModelRatio::COST_PER_REQUEST, 'remark' => 'Codex 全系默认：1 请求 = 1 单位'],
            ['vendor' => 'openai', 'model' => 'gpt-', 'match_type' => CodingPlanModelRatio::MATCH_PREFIX, 'unit_cost' => 1.0, 'cost_mode' => CodingPlanModelRatio::COST_PER_REQUEST, 'remark' => 'GPT 系默认：1 请求 = 1 单位'],
            // Google Gemini：按请求提交
            ['vendor' => 'google', 'model' => 'gemini-', 'match_type' => CodingPlanModelRatio::MATCH_PREFIX, 'unit_cost' => 1.0, 'cost_mode' => CodingPlanModelRatio::COST_PER_REQUEST, 'remark' => 'Gemini 全系默认：1 请求 = 1 单位'],
            // Alibaba Qwen：按千 token（示例费率，管理员可调）
            ['vendor' => 'alibaba', 'model' => 'qwen3-coder-plus', 'match_type' => CodingPlanModelRatio::MATCH_EXACT, 'unit_cost' => 0.004, 'cost_mode' => CodingPlanModelRatio::COST_PER_1K_TOKENS, 'remark' => '示例费率：¥4/百万 token'],
            ['vendor' => 'alibaba', 'model' => 'qwen-', 'match_type' => CodingPlanModelRatio::MATCH_PREFIX, 'unit_cost' => 0.002, 'cost_mode' => CodingPlanModelRatio::COST_PER_1K_TOKENS, 'remark' => 'Qwen 系兜底：按千 token 计费'],
        ];

        foreach ($ratios as $r) {
            CodingPlanModelRatio::query()->updateOrCreate(
                [
                    'vendor' => $r['vendor'],
                    'match_type' => $r['match_type'],
                    'model' => $r['model'],
                ],
                $r + ['status' => 1, 'created_at' => $now, 'updated_at' => $now]
            );
        }
    }
}