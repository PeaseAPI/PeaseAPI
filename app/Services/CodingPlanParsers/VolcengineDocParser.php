<?php

declare(strict_types=1);

namespace App\Services\CodingPlanParsers;

/**
 * 火山方舟模型定价解析器（doccenter API：https://docs.volcengine.com/api/doc/getDocDetail?DocumentID=1544106&lang=zh）。
 *
 * 源定位（2026-09-12）：doccenter 为 garfish SPA（llms.txt 不存在、页面壳无内嵌数据）；
 * 从前端 bundle（doccenter/main.js）挖出文档 XHR API `/api/doc/getDocDetail`，
 * 参数名 DocumentID（旧 doc id 1099320 已 301 → 1544106，DocumentCode=model-pricing）。
 * 响应 Result.Content 是 **Quill delta JSON 字符串**，zone 结构：
 *  - zoneType=Z 正文、R 表格行、C 单元格容器（insert.id 引用）、x* 单元格内容（真实文本）。
 *
 * 口径决策：
 *  - 方舟按量价为「元/百万 token」（**CNY**），与 DB USD rate 字段不符 → parsePricing 返回空，
 *    三率接入统一等 P7-1 currency 字段（结构已探明：分段计费文本在 Z zone，表格价在 x* cell）；
 *  - 本轮产出模型目录（catalog 无币种问题）：doubao-* 模型 id 散布在正文与单元格文本中，
 *    逐 zone 扫描字符串 insert 提取。
 */
class VolcengineDocParser extends AbstractCodingPlanParser
{
    public function parsePricing(string $body): array
    {
        return []; // 元/百万 token（CNY）→ 归 P7-1 currency 字段
    }

    public function parseCatalog(string $body): array
    {
        $payload = json_decode($body, true);
        if (! is_array($payload)) {
            return []; // 非文档 API 响应（错误页/壳 HTML）
        }
        $content = $payload['Result']['Content'] ?? null;
        if (! is_string($content) || $content === '') {
            return [];
        }
        $delta = json_decode($content, true);
        if (! is_array($delta)) {
            return [];
        }

        $ids = [];
        foreach (($delta['data'] ?? []) as $zone) {
            if (! is_array($zone)) {
                continue;
            }
            foreach (($zone['ops'] ?? []) as $op) {
                $insert = $op['insert'] ?? null;
                if (! is_string($insert)) {
                    continue; // C/R zone 的 id 引用（{"id":"..."}）跳过
                }
                if (preg_match_all('/doubao-[a-z0-9][a-z0-9.-]*/i', $insert, $matches)) {
                    foreach ($matches[0] as $id) {
                        $ids[] = mb_strtolower(rtrim($id, '.-'));
                    }
                }
            }
        }

        return array_values(array_unique($ids));
    }
}
