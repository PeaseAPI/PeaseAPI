<?php

declare(strict_types=1);

namespace App\Services\CodingPlanParsers;

/**
 * 腾讯云 TokenHub Token Plan 解析器（https://cloud.tencent.com/document/product/1823/130060，Slate SSR）。
 *
 * 页面结构（2026-09-12 快照核实，611KB）：
 *  - 套餐表 ×2（通用/Hy × Lite/Standard/Pro/Max：积分配额 + 元/月）——订阅制档位，
 *    非模型按量价，不产出定价条目（档位归 coding_plan_vendor_tiers / P2 promotions）；
 *  - 「Model Name | Model ID | 说明」表 ×2（通用 + Hy）：模型目录，Model ID 格是 Slate 列表，
 *    每个变体 ID 独立 <div class="tse-markdown-ul">（如 deepseek-v4-flash 有 202605/0731/无后缀三个），
 *    逐个拆出 → kind=model_catalog 目录条目；
 *  - 「可用模型」概览列的显示名带下线注记（GLM-5/5.1 将于 2026-10-09 下线）——模型一旦从
 *    Model ID 表移除，diffCatalogModels 会自然报 missing（P2-2 model_retirement 信号）。
 */
class TencentTokenHubParser extends AbstractCodingPlanParser
{
    public function parsePricing(string $body): array
    {
        return []; // 套餐积分页无模型按量价（档位配额走 vendors_tiers/promotions）
    }

    public function parseCatalog(string $body): array
    {
        $models = [];
        foreach ($this->tableRowCells($body) as $cells) {
            if (count($cells) < 2) {
                continue;
            }
            $ids = $this->slateIds($cells[1]);
            if ($ids === []) {
                continue;
            }
            // 目录行特征：ID 格内全部 Slate 文本均为 ID 形态。概览格/工具生态格混有
            // 中文描述文本（「DeepSeek-V4-Flash 正式版」「WorkBuddy腾讯云…」）→ 整行跳过；
            // 表头「Model ID」含空格、套餐格「每订阅月780 积分」同样不通过。
            foreach ($ids as $id) {
                if (! preg_match('/^[a-z][a-z0-9._\/-]{1,96}$/i', $id)) {
                    continue 2;
                }
            }
            foreach ($ids as $id) {
                $models[mb_strtolower($id)] = true;
            }
        }

        return array_keys($models);
    }

    /**
     * 整页 <tr> 平铺扫描（Slate 表格无独立 <table> 语义行结构也用 tr/td），返回每行原始 td HTML。
     *
     * @return list<list<string>>
     */
    protected function tableRowCells(string $body): array
    {
        if (! preg_match_all('/<tr[^>]*>(.*?)<\/tr>/is', $body, $rowMatches)) {
            return [];
        }

        $rows = [];
        foreach ($rowMatches[1] as $row) {
            if (! preg_match_all('/<td[^>]*>(.*?)<\/td>/is', $row, $cellMatches)) {
                continue;
            }
            $rows[] = $cellMatches[1];
        }

        return $rows;
    }

    /**
     * Slate 单元格 HTML → 变体 ID 列表（每个 tse-markdown-ul div 一个 ID）。
     *
     * @return list<string>
     */
    protected function slateIds(string $cellHtml): array
    {
        if (! preg_match_all('/data-slate-string="true"[^>]*>([^<]+)</', $cellHtml, $matches)) {
            return [];
        }

        $ids = [];
        foreach ($matches[1] as $raw) {
            $id = trim(html_entity_decode($raw, ENT_QUOTES | ENT_HTML5, 'UTF-8'));
            if ($id !== '') {
                $ids[] = $id;
            }
        }

        return $ids;
    }
}
