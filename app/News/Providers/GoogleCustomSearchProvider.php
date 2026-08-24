<?php

declare(strict_types=1);

namespace App\News\Providers;

use App\Enums\ChannelType;
use App\Models\Channel;
use App\News\NewsArticle;
use App\News\NewsSearchRequest;
use App\News\NewsSearchResult;

/**
 * Google Custom Search JSON API 适配器
 *
 * 文档: https://developers.google.com/custom-search/v1/overview
 * 认证: query 参数 key；cx（自定义搜索引擎 ID）存放在渠道 setting 中
 * 限制: num 最大 10
 */
class GoogleCustomSearchProvider extends AbstractNewsProvider
{
    public function getProviderKey(): string
    {
        return 'google_custom_search';
    }

    public function getChannelType(): ChannelType
    {
        return ChannelType::GOOGLE_CUSTOM_SEARCH;
    }

    public function search(NewsSearchRequest $request, Channel $channel): NewsSearchResult
    {
        $key = $this->getKey($channel);
        $cx = $this->getSetting($channel, 'cx', '');
        if ($key === '' || $cx === '') {
            throw new \RuntimeException('Google Custom Search requires both API key (key) and search engine id (setting.cx)');
        }

        // Google CSE 单次最多返回 10 条
        $num = min($request->maxResults, 10);

        $params = [
            'key' => $key,
            'cx' => $cx,
            'q' => $request->query,
            'num' => $num,
            'start' => max(1, $request->start + 1),
        ];

        if ($request->sortBy === 'date') {
            $params['sort'] = 'date';
        }
        if ($request->language) {
            // Google lr 参数格式: lang_zh-CN / lang_en
            $params['lr'] = 'lang_'.str_replace('-', '_', $request->language);
        }

        $data = $this->httpGet($this->getBaseUrl($channel).'/customsearch/v1', $params);

        $articles = [];
        foreach ($data['items'] ?? [] as $item) {
            $metaDescription = $item['pagemap']['metatags'][0]['og:description']
                ?? $item['pagemap']['metatags'][0]['description']
                ?? null;

            $articles[] = new NewsArticle(
                title: $item['title'] ?? null,
                url: $item['link'] ?? null,
                description: $item['snippet'] ?? null,
                content: $metaDescription,
                source: $item['displayLink'] ?? null,
            );
        }

        return new NewsSearchResult(
            articles: $articles,
            totalResults: (int) ($data['searchInformation']['totalResults'] ?? count($articles)),
            provider: $this->getProviderKey(),
            raw: $this->shouldIncludeRaw() ? $data : null,
        );
    }
}
