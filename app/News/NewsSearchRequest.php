<?php

declare(strict_types=1);

namespace App\News;

/**
 * 归一化新闻搜索请求 DTO
 *
 * 将不同来源（HTTP 请求 / 内部调用）的搜索参数统一为标准结构，
 * 再由各 Provider 适配器转换为上游 API 所需格式。
 */
class NewsSearchRequest
{
    public function __construct(
        /** 搜索关键词（必填） */
        public string $query,
        /** Provider 标识：google_custom_search / news_api / tavily / exa；为空则自动选择可用渠道 */
        public ?string $provider = null,
        /** 期望返回结果数量 */
        public int $maxResults = 10,
        /** 结果起始偏移（分页），部分 Provider 支持 */
        public int $start = 0,
        /** 语言代码，如 en / zh */
        public ?string $language = null,
        /** 排序方式：relevancy / date / popularity */
        public ?string $sortBy = null,
        /** 起始日期 YYYY-MM-DD（仅返回此日期之后的文章） */
        public ?string $fromDate = null,
        /** 结束日期 YYYY-MM-DD */
        public ?string $toDate = null,
        /** 限定域名（白名单） */
        public array $includeDomains = [],
        /** 排除域名（黑名单） */
        public array $excludeDomains = [],
        /** 主题分类：news / general / research 等 */
        public ?string $topic = null,
        /** 是否返回摘要/答案（Tavily include_answer、Exa contents.text） */
        public bool $includeAnswer = false,
    ) {}

    /**
     * 从 HTTP 请求参数构造（兼容 JSON body 与 query string）
     *
     * @param  array<string, mixed>  $input
     */
    public static function fromArray(array $input): self
    {
        $maxResults = (int) ($input['max_results'] ?? $input['num'] ?? 10);
        $limit = (int) config('pease-api.news.max_results_limit', 50);
        $maxResults = max(1, min($maxResults, $limit));

        // 分页偏移：优先 start，其次 page 转换
        $start = 0;
        if (isset($input['start'])) {
            $start = max(0, (int) $input['start']);
        } elseif (isset($input['page'])) {
            $start = (max(1, (int) $input['page']) - 1) * $maxResults;
        }

        return new self(
            query: trim((string) ($input['query'] ?? $input['q'] ?? '')),
            provider: isset($input['provider']) ? strtolower((string) $input['provider']) : null,
            maxResults: $maxResults,
            start: $start,
            language: $input['language'] ?? $input['lang'] ?? null,
            sortBy: $input['sort_by'] ?? $input['sortBy'] ?? null,
            fromDate: $input['from'] ?? $input['from_date'] ?? null,
            toDate: $input['to'] ?? $input['to_date'] ?? null,
            includeDomains: self::parseDomains($input['include_domains'] ?? $input['domains'] ?? []),
            excludeDomains: self::parseDomains($input['exclude_domains'] ?? []),
            topic: $input['topic'] ?? null,
            includeAnswer: (bool) ($input['include_answer'] ?? false),
        );
    }

    /**
     * @param  mixed  $value
     */
    protected static function parseDomains($value): array
    {
        if (is_array($value)) {
            return array_values(array_filter(array_map('trim', $value)));
        }
        if (is_string($value) && $value !== '') {
            return array_values(array_filter(array_map('trim', explode(',', $value))));
        }

        return [];
    }

    /**
     * 校验请求参数
     */
    public function validate(): array
    {
        $errors = [];
        if ($this->query === '') {
            $errors['query'] = 'query is required';
        }
        if (mb_strlen($this->query) > 2048) {
            $errors['query'] = 'query is too long (max 2048 chars)';
        }

        return $errors;
    }
}
