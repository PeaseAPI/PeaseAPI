<?php

declare(strict_types=1);

namespace App\News\Providers;

use App\Enums\ChannelType;
use App\Models\Channel;
use App\News\NewsArticle;
use App\News\NewsSearchRequest;
use App\News\NewsSearchResult;

/**
 * NewsAPI.org 适配器
 *
 * 端点: GET /v2/everything（全文搜索）/ /v2/top-headlines（头条）
 * 认证: query 参数 apiKey
 */
class NewsApiProvider extends AbstractNewsProvider
{
    public function getProviderKey(): string
    {
        return 'news_api';
    }

    public function getChannelType(): ChannelType
    {
        return ChannelType::NEWS_API;
    }

        public function isNewsOnly(): bool
    {
        return true;
    }

    public function search(NewsSearchRequest $request, Channel $channel): NewsSearchResult
    {
        $key = $this->getKey($channel);
        if ($key === '') {
            throw new \RuntimeException('NewsAPI requires an API key');
        }

        // topic=headlines 时走 top-headlines 端点
        $useTopHeadlines = $request->topic === 'headlines' || $request->topic === 'top';

        $endpoint = $useTopHeadlines ? '/v2/top-headlines' : '/v2/everything';

        $params = [
            'apiKey' => $key,
        ];

        if ($useTopHeadlines) {
            $params['q'] = $request->query;
            $params['pageSize'] = $request->maxResults;
            if ($request->language) {
                $params['country'] = $this->languageToCountry($request->language);
            }
        } else {
            $params['q'] = $request->query;
            $params['pageSize'] = $request->maxResults;
            $params['page'] = max(1, (int) floor($request->start / max($request->maxResults, 1)) + 1);
            if ($request->sortBy) {
                $params['sortBy'] = $request->sortBy;
            }
            if ($request->language) {
                $params['language'] = $request->language;
            }
            if ($request->fromDate) {
                $params['from'] = $request->fromDate;
            }
            if ($request->toDate) {
                $params['to'] = $request->toDate;
            }
            if (! empty($request->includeDomains)) {
                $params['domains'] = implode(',', $request->includeDomains);
            }
        }

        $data = $this->httpGet($this->getBaseUrl($channel).$endpoint, $params);

        $articles = [];
        foreach ($data['articles'] ?? [] as $item) {
            $articles[] = new NewsArticle(
                title: $item['title'] ?? null,
                url: $item['url'] ?? null,
                description: $item['description'] ?? null,
                content: $item['content'] ?? null,
                author: $item['author'] ?? null,
                source: $item['source']['name'] ?? null,
                publishedAt: $item['publishedAt'] ?? null,
                imageUrl: $item['urlToImage'] ?? null,
            );
        }

        return new NewsSearchResult(
            articles: $articles,
            totalResults: (int) ($data['totalResults'] ?? count($articles)),
            provider: $this->getProviderKey(),
            raw: $this->shouldIncludeRaw() ? $data : null,
        );
    }

    /**
     * 将语言代码映射为 NewsAPI top-headlines 所需的国家代码
     */
    protected function languageToCountry(string $language): string
    {
        $map = [
            'zh' => 'cn', 'zh-CN' => 'cn', 'zh-TW' => 'tw',
            'en' => 'us', 'ja' => 'jp', 'ko' => 'kr',
            'de' => 'de', 'fr' => 'fr', 'es' => 'es', 'it' => 'it', 'ru' => 'ru',
        ];

        return $map[$language] ?? 'us';
    }
}
