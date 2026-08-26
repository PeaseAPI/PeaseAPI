<?php

declare(strict_types=1);

namespace App\News\Providers;

use App\Enums\ChannelType;
use App\Models\Channel;
use App\News\NewsArticle;
use App\News\NewsSearchRequest;
use App\News\NewsSearchResult;

/**
 * Brave Search API 适配器
 *
 * 端点: GET /res/v1/web/search
 * 认证: header X-Subscription-Token
 * 特色: 独立搜索引擎，支持新闻/网页搜索，无需依赖 Google
 */
class BraveSearchProvider extends AbstractNewsProvider
{
    public function getProviderKey(): string
    {
        return 'brave_search';
    }

    public function getChannelType(): ChannelType
    {
        return ChannelType::BRAVE_SEARCH;
    }

    public function search(NewsSearchRequest $request, Channel $channel): NewsSearchResult
    {
        $key = $this->getKey($channel);
        if ($key === '') {
            throw new \RuntimeException('Brave Search requires an API key');
        }

        $params = [
            'q' => $request->query,
            'count' => $request->maxResults,
            'offset' => $request->start,
        ];

        // 搜索类型：新闻或网页
        if ($request->topic === 'news') {
            $params['freshness'] = 'pw'; // 过去一周
            $params['result_filter'] = 'news';
        }

        if ($request->language) {
            $params['search_lang'] = $request->language;
        }

        if ($request->sortBy === 'date') {
            $params['freshness'] = 'pd'; // 过去一天
        }

        if ($request->fromDate) {
            $params['freshness'] = $request->fromDate . 'to' . ($request->toDate ?? 'now');
        }

        $data = $this->httpGet(
            $this->getBaseUrl($channel) . '/res/v1/web/search',
            $params,
            [
                'X-Subscription-Token' => $key,
                'Accept' => 'application/json',
            ]
        );

        $articles = [];
        $webResults = $data['web']['results'] ?? [];
        $newsResults = $data['news']['results'] ?? [];
        $allResults = ! empty($newsResults) ? $newsResults : $webResults;

        foreach ($allResults as $item) {
            $articles[] = new NewsArticle(
                title: $item['title'] ?? null,
                url: $item['url'] ?? null,
                description: $item['description'] ?? null,
                content: $item['description'] ?? null,
                source: $item['source'] ?? null,
                publishedAt: $item['age'] ?? $item['date'] ?? null,
                imageUrl: $item['thumbnail'] ?? $item['img'] ?? null,
            );
        }

        return new NewsSearchResult(
            articles: $articles,
            totalResults: (int) ($data['web']['totalResults'] ?? count($articles)),
            provider: $this->getProviderKey(),
            raw: $this->shouldIncludeRaw() ? $data : null,
        );
    }
}
