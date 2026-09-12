<?php

declare(strict_types=1);

namespace App\Services\CodingPlanParsers;

/**
 * 硅基流动（SiliconFlow）按量价目页解析器
 * （https://siliconflow.cn/pricing，SSR 直出）。
 *
 * 源形态（2026-09-12）：Tailwind 静态 HTML（~225KB），每行 class
 * `pricing-row-{text|image|audio|video}-{ts}`（text=LLM，其余=多模态），
 * 模型入口为 <a title="org/model">（href=cloud.siliconflow.cn/models?target=…）：
 *  - 完整 API id 含 org 段（zai-org/GLM-5.3、deepseek-ai/DeepSeek-V3）；
 *  - `Pro/` 前缀 = 加速服务标记（Pro/zai-org/GLM-5.1），非模型 id 的一部分；
 *  - 「费用发生时段: 9点～18点」双时段价组（高峰/空闲两列价）。
 *
 * 口径决策：按量价 ¥/百万 tokens（CNY）→ 厂商 currency 字段已标 CNY（迁移 000010），
 * 折算比率行归 P7-2/P7-3（汇率/结算体系）落地后接入 → parsePricing 返回空；
 * 目录抽全部 52 个模型 id（text/image/audio/video 全量，管理员确认时筛选）。
 */
class SiliconflowParser extends AbstractCodingPlanParser
{
    public function parsePricing(string $body): array
    {
        return []; // ¥/M tokens（CNY）→ 折算比率归 P7-2/P7-3
    }

    public function parseCatalog(string $body): array
    {
        $ids = [];
        if (preg_match_all('/<a\b[^>]*\btitle="([^"]+)"/i', $body, $matches)) {
            foreach ($matches[1] as $title) {
                $model = $this->modelName($title);
                if ($model !== null && str_contains($model, '/')) {
                    // 加速服务前缀（Pro/…）非模型 id 的一部分
                    if (str_starts_with($model, 'pro/')) {
                        $model = substr($model, 4);
                    }
                    $ids[] = $model; // modelName 已小写化
                }
            }
        }

        return array_values(array_unique($ids));
    }
}
