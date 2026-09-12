<?php

declare(strict_types=1);

namespace App\Services\CodingPlanParsers;

/**
 * 联通云 AISP Coding/Token Plan 解析器
 * （https://support.cucloud.cn/document/127/591/2357.html?id=2357&arcid=7015）。
 *
 * 源定位（2026-09-12）：DedeCMS 模板壳**无 XHR**——文档树与全部正文直接内嵌页面 JS：
 * `totalList = [ {...全站目录树...} ];`（16.5MB，documentEntityList[].content = 富文本 HTML，
 * id 字段即 URL 上的 arcid：7015=Coding Plan概述、7080=Token Plan概述，共 113 篇）。
 * 定价结构（正文表）：
 *  - Coding Plan：套餐档位 Lite 40 元/Pro 200 元（CNY，量纲=请求次数）+「云区域|支持模型|BaseURL」表；
 *  - Token Plan：个人版 15/30/45 元、团队版 198/698/1398 元（CNY）+ credits 折算「综合单价 元/百万tokens」
 *    （参考价，非独立按量价）+「云区域|云区域支持套餐类型|支持模型|BaseURL」表。
 *
 * 口径决策：
 *  - 套餐为人民币且量纲是「请求次数/credits/tokens 额度」→ parsePricing 返回空，
 *    档位归 P2-1、币种归 P7-1（价目已有迁移 000006/000008 核对预置）；
 *  - 本轮产出模型目录：抽取 7015/7080 两篇「支持模型」列，格内顿号/换行拆分 +
 *    「别名：说明」取冒号前段（aisp-auto-route：智能路由 → aisp-auto-route），
 *    过 modelName() 白名单（含中文的说明尾自动滤除）。
 */
class UnicomParser extends AbstractCodingPlanParser
{
    /** 要抽取「支持模型」目录的文档 id（arcid） */
    private const CATALOG_DOC_IDS = ['7015', '7080'];

    public function parsePricing(string $body): array
    {
        return []; // 套餐档位（CNY 元/月，次数/credits 额度）→ 归 P2-1；币种归 P7-1
    }

    public function parseCatalog(string $body): array
    {
        $docs = $this->extractEmbeddedDocuments($body);
        if ($docs === []) {
            return [];
        }

        $ids = [];
        foreach ($docs as $docId => $contentHtml) {
            if (! in_array((string) $docId, self::CATALOG_DOC_IDS, true)) {
                continue;
            }
            foreach ($this->htmlTables($contentHtml) as $rows) {
                if ($rows === []) {
                    continue;
                }
                $modelIdx = null;
                foreach ($rows[0] as $idx => $cell) {
                    if (trim($cell) === '支持模型') {
                        $modelIdx = $idx;
                        break;
                    }
                }
                if ($modelIdx === null) {
                    continue; // 套餐档位表 / 购买时长示例表
                }
                foreach (array_slice($rows, 1) as $row) {
                    $cell = $row[$modelIdx] ?? '';
                    $cell = $this->cleanText($cell);
                    if ($cell === '') {
                        continue;
                    }
                    // 格内多模型：顿号/换行/分号/空白分隔（cleanText 已把 <br> 归为空格）；
                    // 「别名：中文说明」取冒号前段（aisp-auto-route：智能路由… → aisp-auto-route）
                    $parts = preg_split('/[、\n；;\s]+/u', $cell) ?: [];
                    foreach ($parts as $part) {
                        [$head] = array_pad(explode('：', $part, 2), 1, $part);
                        $model = $this->modelName($head);
                        if ($model !== null) {
                            $ids[] = $model; // modelName 已小写化
                        }
                    }
                }
            }
        }

        return array_values(array_unique($ids));
    }

    /**
     * JSON 深度状态机：从 $start（'[' 或 '{'）扫描到根结构闭合字符，返回其下标。
     * 必须手写——页面 JSON 之后还有约 27KB 其他 JS 代码才出现 "];"，而 PHP json_decode
     * 不容忍尾部数据（python raw_decode 可以），按 "];" 截取会塞进垃圾导致 Syntax error。
     */
    private function locateJsonEnd(string $body, int $start): ?int
    {
        $depth = 0;
        $inString = false;
        $escaped = false;
        $len = strlen($body);
        for ($i = $start; $i < $len; $i++) {
            $ch = $body[$i];
            if ($inString) {
                if ($escaped) {
                    $escaped = false;
                } elseif ($ch === '\\') {
                    $escaped = true;
                } elseif ($ch === '"') {
                    $inString = false;
                }

                continue;
            }
            if ($ch === '"') {
                $inString = true;
            } elseif ($ch === '[' || $ch === '{') {
                $depth++;
            } elseif ($ch === ']' || $ch === '}') {
                $depth--;
                if ($depth === 0) {
                    return $i;
                }
            }
        }

        return null;
    }

    /**
     * 格内模型多为独立 <p>/<br> 段（</p><p> 间无空白）→ 块级结束标签换成换行，
     * 否则 cleanText 剥标签后粘连（deepseek-v4-flashglm-5.1）。
     */
    protected function prepareCellHtml(string $cellHtml): string
    {
        $cellHtml = str_replace(["\u{00A0}", '&nbsp;'], ' ', $cellHtml);

        return preg_replace('/<\/(?:p|div|li|tr|h[1-6])\s*\/?>/i', "\n", $cellHtml) ?? $cellHtml;
    }

    /**
     * 从页面 JS 提取内嵌文档树 → [docId => content HTML]。
     * 正文 content 为 HTML 字符串字段（id 字段即 URL 的 arcid 参数）。
     *
     * @return array<string, string>
     */
    private function extractEmbeddedDocuments(string $body): array
    {
        $cache = [];

        $marker = 'totalList = [';
        $start = strpos($body, $marker);
        if ($start === false) {
            return $cache;
        }
        $start += strlen($marker) - 1; // 指向 '['
        $end = $this->locateJsonEnd($body, $start);
        if ($end === null) {
            return $cache;
        }
        $tree = json_decode(substr($body, $start, $end - $start + 1), true);
        if (! is_array($tree)) {
            return $cache;
        }

        // BFS 遍历目录树（childList × documentEntityList[].content）
        $queue = [$tree];
        while ($queue !== []) {
            $node = array_shift($queue);
            if (is_array($node)) {
                foreach ($node as $key => $value) {
                    if ($key === 'documentEntityList' && is_array($value)) {
                        foreach ($value as $ent) {
                            $id = is_array($ent) ? ($ent['id'] ?? null) : null;
                            $content = is_array($ent) ? ($ent['content'] ?? null) : null;
                            if (is_string($id) && $id !== '' && is_string($content) && str_contains($content, '<')) {
                                $cache[$id] = $content;
                            }
                        }
                    } elseif (is_array($value)) {
                        $queue[] = $value;
                    }
                }
            }
        }

        return $cache;
    }
}
