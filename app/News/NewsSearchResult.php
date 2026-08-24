<?php

declare(strict_types=1);

namespace App\News;

/**
 * 归一化新闻搜索结果集 DTO
 */
class NewsSearchResult
{
    /**
     * @param  NewsArticle[]  $articles
     * @param  array<string, mixed>|null  $raw  原始上游响应（调试用）
     */
    public function __construct(
        public array $articles = [],
        public ?string $answer = null,
        public int $totalResults = 0,
        public ?string $provider = null,
        public ?array $raw = null,
    ) {}

    /**
     * 序列化为数组
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'provider' => $this->provider,
            'total_results' => $this->totalResults,
            'answer' => $this->answer,
            'articles' => array_map(fn (NewsArticle $a) => $a->toArray(), $this->articles),
            'count' => count($this->articles),
            'raw' => $this->raw,
        ];
    }
}
