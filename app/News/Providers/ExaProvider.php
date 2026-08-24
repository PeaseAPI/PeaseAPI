<?php

declare(strict_types=1);

namespace App\News\Providers;

use App\Enums\ChannelType;
use App\Models\Channel;
use App\News\NewsArticle;
use App\News\NewsSearchRequest;
use App\News\NewsSearchResult;

/**
 * Exa Search API 适配器
 *
 * 端点: POST /search
 * 认证: header x-api-key
 * 特色: 神经/关键词混合搜索，支持 contents.text / highlights
 */
class ExaProvider extends AbstractNewsProvider
{
    public function getProviderKey(): string
    {
        return 'exa';
    }

    public function getChannelType(): ChannelType
    {
        return ChannelType::EXA;
    }

    public function search(NewsSearchRequest $request, Channel $channel): NewsSearchResult
    {
        $key = $this->getKey($channel);
        if ($key === '') {
            throw new \RuntimeException('Exa requires an API key');
        }

        $body = [
            'query' => $request->query,
            'numResults' => $request->maxResults,
            'contents' => [
                'text' => true,
            ],
            'type' => $this->getSetting($channel, 'search_type', 'auto'),
        ];

        if ($request->fromDate) {
            $body['startPublishedDate'] = $request->fromDate;
        }
        if ($request->toDate) {
            $body['endPublishedDate'] = $request->toDate;
        }
        if (! empty($request->includeDomains)) {
            $body['includeDomains'] = $request->includeDomains;
        }
        if (! empty($request->excludeDomains)) {
            $body['excludeDomains'] = $request->excludeDomains;
        }
        if ($request->topic) {
            $body['category'] = $request->topic === 'news' ? 'news' : $request->topic;
        }

        $data = $this->httpPost(
            $this->getBaseUrl($channel).'/search',
            $body,
            [
                'x-api-key' => $key,
                'Content-Type' => 'application/json',
            ]
        );

        $articles = [];
        foreach ($data['results'] ?? [] as $item) {
            $articles[] = new NewsArticle(
                title: $item['title'] ?? null,
                url: $item['url'] ?? null,
                content: $item['text'] ?? null,
                author: $item['author'] ?? null,
                publishedAt: $item['publishedDate'] ?? null,
                imageUrl: $item['image'] ?? null,
            );
        }

        return new NewsSearchResult(
            articles: $articles,
            totalResults: count($articles),
            provider: $this->getProviderKey(),
            raw: $this->shouldIncludeRaw() ? $data : null,
        );
    }
}
