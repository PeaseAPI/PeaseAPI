<?php

declare(strict_types=1);

namespace App\News;

/**
 * 归一化新闻文章 DTO
 *
 * 统一各新闻 / 搜索 API 的文章字段，供前端与下游消费方使用。
 */
class NewsArticle
{
    public function __construct(
        /** 文章标题 */
        public ?string $title = null,
        /** 文章链接 */
        public ?string $url = null,
        /** 摘要 / 描述 */
        public ?string $description = null,
        /** 正文内容（部分 Provider 返回） */
        public ?string $content = null,
        /** 作者 */
        public ?string $author = null,
        /** 来源名称（媒体 / 站点） */
        public ?string $source = null,
        /** 发布时间 ISO-8601 字符串 */
        public ?string $publishedAt = null,
        /** 配图 URL */
        public ?string $imageUrl = null,
        /** 相关性评分（部分 Provider 返回） */
        public ?float $score = null,
    ) {}

    /**
     * 序列化为数组
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return array_filter([
            'title' => $this->title,
            'url' => $this->url,
            'description' => $this->description,
            'content' => $this->content,
            'author' => $this->author,
            'source' => $this->source,
            'published_at' => $this->publishedAt,
            'image_url' => $this->imageUrl,
            'score' => $this->score,
        ], fn ($v) => $v !== null && $v !== '');
    }
}
