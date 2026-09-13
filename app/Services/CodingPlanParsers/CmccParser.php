<?php

declare(strict_types=1);

namespace App\Services\CodingPlanParsers;

/**
 * 移动云 MoMA 按量计价解析器（cloud-cms-service-web CMS API，两步抓取的第二步 HTML 正文）。
 *
 * 源定位（2026-09-13 探测反转）：P1-2 期结论「OIDC 网关匿名 302 不可行」只适用于
 * /api/web/op-help-center/request-api/ 前缀；页面同源网关
 * https://ecloud.10086.cn/op-help-center/request-api/service-api/... **匿名可读**：
 *   ① GET service-api/outline/tree?outlineId=972 → 全站文档树（文章 id + 标题）
 *   ② GET service-api/article/info/{id}          → 元信息（data.content = EOS 对象存储文件名，32 位 hex）
 *   ③ GET service-api/article/content/{文件名}   → 正文裸 HTML（非 JSON 包装，~50KB）
 * 文件名随内容发布变化 → 注册表 pricing_url 固定为 ②（info 端点，文章 id 永不变），
 * 由 sync 层 content_follow 选项完成 ②→③（/article/info/ → /article/content/）。
 *
 * 正文结构（91592「Token按量计费-自营模型」，元/百万 tokens，CNY，夜间 00:00-08:00 优惠）：
 *  - 一、文本/视觉/向量/排序模型：模型名称（系列名，含 rowspan 续行）| 规格名称（API id，
 *    同格逗号/顿号并列）| 输入/输出tokens | 单价（元/百万tokens）；
 *  - 二、视频生成模型：模型名称（系列名）| 资费场景（真 id，rowspan）| 计价项 | 单价（元/秒、元/张）。
 *
 * 口径决策：两类表的 id 都在「系列名列的右一列」（文本=规格名称、视频=资费场景），
 * 故先按「规格名称」表头定位，无则取「模型名称」列右一列；系列名（「DeepSeek系列」等
 * 含中文格）被 modelName 白名单拒；rowspan 续行格为 &nbsp; 空文本自动跳过。
 * parsePricing 恒空 —— 按量价元/百万 tokens（CNY）归 P3-4 迁移预置（豆率换算），
 * 夜间时段价可映射 time_discounts。
 */
class CmccParser extends AbstractCodingPlanParser
{
    public function parsePricing(string $body): array
    {
        return []; // 元/百万 tokens（CNY）→ P3-4 迁移预置
    }

    public function parseCatalog(string $body): array
    {
        $ids = [];
        foreach ($this->htmlTables($body) as $rows) {
            if ($rows === []) {
                continue;
            }
            $idx = $this->idColumnIndex($rows[0]);
            if ($idx === null) {
                continue; // 量包表 / 说明表 / 非模型表
            }
            foreach (array_slice($rows, 1) as $row) {
                foreach (preg_split('/[,，、]/u', $this->cleanText($row[$idx] ?? '')) ?: [] as $part) {
                    $model = $this->modelName($part);
                    if ($model !== null && ! in_array($model, $ids, true)) {
                        $ids[] = $model;
                    }
                }
            }
        }

        return $ids;
    }

    /**
     * 定位 API id 列：优先「规格名称」表头（文本/视觉/向量/排序表）；
     * 否则「模型名称」表头的右一列（视频/语音表 —— 系列名列旁的 id/资费场景列）。
     */
    protected function idColumnIndex(array $header): ?int
    {
        $nameIndex = null;
        foreach ($header as $idx => $cell) {
            $cell = trim(str_replace(' ', '', $cell));
            if ($cell === '规格名称') {
                return (int) $idx;
            }
            if ($cell === '模型名称' && $nameIndex === null) {
                $nameIndex = (int) $idx;
            }
        }

        return $nameIndex !== null && isset($header[$nameIndex + 1]) ? $nameIndex + 1 : null;
    }
}
