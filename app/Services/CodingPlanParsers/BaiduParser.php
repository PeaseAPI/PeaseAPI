<?php

declare(strict_types=1);

namespace App\Services\CodingPlanParsers;

/**
 * 百度千帆模型定价解析器（https://cloud.baidu.com/doc/qianfan/s/wmh4sv6ya，SSR HTML）。
 *
 * 源定位（2026-09-12）：旧千帆文档 doc/QIANFAN/s/hlpl7xe2f 已 302 → 文档站 index；
 * 从 index 页「模型服务计费」入口定位新页 qianfan/s/wmh4sv6ya（415KB SSR，217 行价格表）。
 * 页面结构（模型服务计费页）：
 *  - 按量表：模型名称 | 版本名称 | 服务内容 | 子项 | 在线推理 | 批量推理 | 单位
 *    （ERNIE 5.1 输入 0.004 / 输出 0.018，「元/千tokens」；子项含 分段档位「输入（输入<=32k）」等）；
 *  - 量包表：量包名称 | 额度 | 速率限制 | 有效期 | 原价(元)。
 *
 * 口径决策：
 *  - 千帆按量价为「元/千 tokens」（**CNY**，无国际美元站）→ parsePricing 返回空，
 *    三率接入等 P7-1 currency 字段（注意其单位天然是 /千 tokens，届时无需 ÷1000）；
 *  - 本轮产出模型目录：「版本名称」列即 API 调用 id，但单元格存在**连排无分隔**
 *    （「ERNIE-5.1ERNIE-5.1-Speed-Preview」）→ 按 (?:ernie|bce)- 前缀前瞻切分，
 *    逐段过 modelName() 白名单校验；量包表无「版本名称」表头自动跳过。
 */
class BaiduParser extends AbstractCodingPlanParser
{
    public function parsePricing(string $body): array
    {
        return []; // 元/千 tokens（CNY）→ 归 P7-1 currency 字段
    }

    public function parseCatalog(string $body): array
    {
        $ids = [];
        foreach ($this->htmlTables($body) as $rows) {
            if ($rows === []) {
                continue;
            }
            $header = $rows[0];
            $versionIdx = null;
            foreach ($header as $idx => $cell) {
                if (trim($cell) === '版本名称') {
                    $versionIdx = $idx;
                    break;
                }
            }
            if ($versionIdx === null) {
                continue; // 量包表 / 非模型表
            }
            foreach (array_slice($rows, 1) as $row) {
                $cell = $this->cleanText($row[$versionIdx] ?? '');
                if ($cell === '') {
                    continue;
                }
                // 连排 id 按 ernie-/bce- 前缀切分（前瞻零宽，不消耗字符）
                $parts = preg_split('/(?i)(?=(?:ernie|bce)-)/', $cell) ?: [];
                foreach ($parts as $part) {
                    $model = $this->modelName($part);
                    if ($model !== null) {
                        $ids[] = $model; // modelName 已小写化
                    }
                }
            }
        }

        return array_values(array_unique($ids));
    }
}
