<?php

declare(strict_types=1);

namespace App\Services\CodingPlanParsers;

/**
 * 解析器公共实现：HTML 表格 / Markdown 表格抽取、文本清洗、数值与模型名解析。
 * 解析器只做「纯函数」转换，不发请求、不碰 DB（快照与 diff 由 OfficialSourceService 编排）。
 */
abstract class AbstractCodingPlanParser implements CodingPlanParserInterface
{
    /**
     * 从 HTML 抽取全部表格行（每行 = 文本单元格数组），跳过全空行。
     *
     * @return list<list<string>>
     */
    protected function tableRows(string $body): array
    {
        if (! preg_match_all('/<tr[^>]*>(.*?)<\/tr>/is', $body, $rowMatches)) {
            return [];
        }

        $rows = [];
        foreach ($rowMatches[1] as $row) {
            if (! preg_match_all('/<t([hd])[^>]*>(.*?)<\/t\1>/is', $row, $cellMatches)) {
                continue;
            }
            $cells = array_map(fn (string $cell): string => $this->cleanText($this->prepareCellHtml($cell)), $cellMatches[2]);
            if (trim(implode('', $cells)) === '') {
                continue; // 分隔行 / 空行
            }
            $rows[] = $cells;
        }

        return $rows;
    }

    /**
     * 按 <table> 分组抽取行（每表 = 行数组的数组），供多表页面区分表头。
     *
     * @return list<list<list<string>>>
     */
    protected function htmlTables(string $body): array
    {
        $tables = [];
        if (! preg_match_all('/<table[^>]*>(.*?)<\/table>/is', $body, $tableMatches)) {
            return [];
        }
        foreach ($tableMatches[1] as $inner) {
            $rows = $this->tableRows((string) $inner);
            if ($rows !== []) {
                $tables[] = $rows;
            }
        }

        return $tables;
    }

    /**
     * 从 Markdown 抽取全部管道表格（header + 数据行）。
     *
     * @return list<array{header: list<string>, rows: list<list<string>>}>
     */
    protected function markdownTables(string $body): array
    {
        $tables = [];
        $lines = preg_split('/\r?\n/', $body) ?: [];
        $count = count($lines);
        for ($i = 0; $i < $count; $i++) {
            if (! str_starts_with(trim($lines[$i]), '|')) {
                continue;
            }
            $header = $this->mdCells($lines[$i]);
            if (count($header) < 2) {
                continue;
            }
            // 分隔行（|---|---|）跳过
            $j = $i + 1;
            if ($j < $count && preg_match('/^\s*\|[\s:|-]+\|?\s*$/', $lines[$j])) {
                $j++;
            }
            $rows = [];
            while ($j < $count && str_starts_with(trim($lines[$j]), '|')) {
                $cells = $this->mdCells($lines[$j]);
                if (trim(implode('', $cells)) !== '') {
                    $rows[] = $cells;
                }
                $j++;
            }
            if ($rows !== []) {
                $tables[] = ['header' => $header, 'rows' => $rows];
            }
            $i = $j - 1;
        }

        return $tables;
    }

    /**
     * 拆 Markdown 表格行为单元格。
     *
     * @return list<string>
     */
    protected function mdCells(string $line): array
    {
        $line = trim($line);
        $line = preg_replace('/^\|/', '', $line) ?? '';
        $line = preg_replace('/\|$/', '', $line) ?? '';

        return array_map(fn (string $cell): string => trim($cell), explode('|', $line));
    }

    /**
     * HTML 片段 → 单行纯文本。
     */
    /**
     * 单元格 HTML 在 cleanText 之前的预处理钩子（默认恒等）。
     * unicom 的格内模型是多个独立 <p> 段（</p><p> 之间无空白），剥标签后会粘连，
     * 覆盖本钩子把块级结束标签换成换行，使 cleanText 后留有空格分隔。
     */
    protected function prepareCellHtml(string $cellHtml): string
    {
        return $cellHtml;
    }

    protected function cleanText(string $html): string
    {
        $html = preg_replace('/<br\s*\/?>/i', ' ', $html) ?? $html;
        $text = html_entity_decode(strip_tags($html), ENT_QUOTES | ENT_HTML5, 'UTF-8');

        return trim((string) preg_replace('/\s+/u', ' ', $text));
    }

    /**
     * 表格首格 → 官方模型名（去括号注释；宽松白名单格式校验，防止把说明文字当模型）。
     */
    protected function modelName(?string $raw): ?string
    {
        $raw = trim((string) $raw);
        if ($raw === '') {
            return null;
        }
        $raw = preg_replace('/[（(][^（）()]*[)）]/u', '', $raw) ?? $raw;
        $model = trim($raw);
        if ($model === '' || ! preg_match('/^[A-Za-z][A-Za-z0-9._\/-]{1,96}$/', $model)) {
            return null;
        }

        return mb_strtolower($model);
    }

    /**
     * 从单元格文本提取首个数值（剥离千分位/货币符号/单位；'-' 或空 → null）。
     */
    protected function number(?string $raw): ?float
    {
        if ($raw === null) {
            return null;
        }
        $raw = trim(str_replace([',', '，', '￥', '¥', '$', '元', '美元'], '', $raw));
        if ($raw === '' || $raw === '-') {
            return null;
        }

        return preg_match('/-?\d+(?:\.\d+)?/', $raw, $m) ? (float) $m[0] : null;
    }

    /**
     * 取括号外的「短上下文价」文本（"4.00（8.00）" → "4.00"；无括号原样返回）。
     */
    protected function shortContext(?string $raw): ?string
    {
        if ($raw === null) {
            return null;
        }
        $cut = preg_split('/[（(]/u', $raw) ?: [$raw];

        return trim($cut[0]);
    }
}
