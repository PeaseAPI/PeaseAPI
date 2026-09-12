<?php

declare(strict_types=1);

namespace App\Services\CodingPlanParsers;

/**
 * 超算互联网（SCNet，中科曙光）Token Plan 解析器
 * （https://www.scnet.cn/ac/openapi/doc/2.0/moduleapi/plans/token-plan.html）。
 *
 * 源形态（2026-09-12）：VitePress 文档站，SSR HTML 直出正文（无需挖 JS/XHR）：
 *  - 套餐档位表：基础版 ¥30（原价 ¥50）/标准版 ¥110（¥185）/高级版 ¥265（¥440）/
 *    旗舰版 ¥764（¥1274），月度 Credits 额度 6 万~180 万（CNY，Credits 量纲）；
 *  - 可用模型表（品牌 | 模型ID | 模型能力 | 支持协议，18 款国产模型）→ 目录源；
 *  - Credits 扣减倍率表（模型名称 | 扣减倍率）→ 折算系数，非按量价。
 *
 * 口径决策：套餐为人民币 Credits 量纲 → parsePricing 返回空（档位归 P2-1、
 * 币种归 P7-1）；目录抽「模型ID」列（表头定位，modelName 白名单兜底）。
 */
class ScnetParser extends AbstractCodingPlanParser
{
    public function parsePricing(string $body): array
    {
        return []; // Credits 套餐档位（CNY 元/月）→ 归 P2-1；扣减倍率/币种归 P7-1
    }

    public function parseCatalog(string $body): array
    {
        $ids = [];
        foreach ($this->htmlTables($body) as $rows) {
            if ($rows === []) {
                continue;
            }
            $modelIdx = null;
            foreach ($rows[0] as $idx => $cell) {
                if (trim($cell) === '模型ID') {
                    $modelIdx = $idx;
                    break;
                }
            }
            if ($modelIdx === null) {
                continue; // 套餐档位表 / 扣减倍率表 / 购买示例表
            }
            foreach (array_slice($rows, 1) as $row) {
                $model = $this->modelName($row[$modelIdx] ?? null);
                if ($model !== null) {
                    $ids[] = $model; // modelName 已小写化
                }
            }
        }

        return array_values(array_unique($ids));
    }
}
