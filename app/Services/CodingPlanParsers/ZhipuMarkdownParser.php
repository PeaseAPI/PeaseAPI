<?php

declare(strict_types=1);

namespace App\Services\CodingPlanParsers;

/**
 * 智谱 BigModel Coding Plan 解析器（https://docs.bigmodel.cn/cn/coding-plan/overview.md，Mintlify .md）。
 *
 * 页面结构（2026-09-12 .md 样本核实，7.8KB 纯 Markdown）：
 *  - 套餐积分表（Lite/Pro/Max × 5 小时积分/每周积分）——订阅制档位，非模型价，
 *    不产出定价条目（套餐档位归 coding_plan_vendor_tiers / P2 promotions 口径）；
 *  - 模型用量上限表（缓存命中率 × GLM‑5.3 / GLM‑5.3‑Flash × 档位）——模型名可提取，
 *    作为 kind=model_catalog 目录源：官方覆盖的模型集合变化 → 上下架检测；
 *  - 按量价目不在本页（GLM 三率已预置迁移 000009，含高峰窗口）。
 *
 * 注意：页面模型名用 U+2011（非断连字符）「GLM‑5.3」，须归一化为普通「-」。
 */
class ZhipuMarkdownParser extends AbstractCodingPlanParser
{
    public function parsePricing(string $body): array
    {
        return []; // 套餐积分页无模型按量价（档位配额走 vendors_tiers/promotions）
    }

    public function parseCatalog(string $body): array
    {
        // GLM‑5.3 / GLM‑5.3-Flash（U+2011 与普通连字符都收）→ 官方覆盖模型集合
        if (! preg_match_all('/GLM[\x{2011}-][0-9][A-Za-z\x{2011}0-9.\-]*/u', $body, $matches)) {
            return [];
        }

        $models = [];
        foreach ($matches[0] as $raw) {
            $model = str_replace("\u{2011}", '-', mb_strtolower(trim($raw)));
            if (preg_match('/^[a-z][a-z0-9.\-]{1,96}$/', $model)) {
                $models[$model] = true;
            }
        }

        return array_keys($models);
    }
}
