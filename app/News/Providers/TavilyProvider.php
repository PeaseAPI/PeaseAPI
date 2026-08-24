<?php

declare(strict_types=1);

namespace App\News\Providers;

use App\Enums\ChannelType;
use App\Models\Channel;
use App\News\NewsArticle;
use App\News\NewsSearchRequest;
use App\News\NewsSearchResult;

/**
 * Tavily Search API 适配器
 *
 * 端点: POST /search
 * 认证: 请求体 api_key
 * 特色: AI 搜索，支持 search_depth / topic / include_answer
 */
class TavilyProvider extends AbstractNewsProvider
{
    public function getProviderKey(): string
    {
        return 'tavily';
    }

    public function getChannelType(): ChannelType
    {
        return ChannelType::TAVILY;
    }

    public function search(NewsSearchRequest $request, Channel $channel): NewsSearchResult
    {
        $key = $this->getKey($channel);
        if ($key === '') {
            throw new \RuntimeException('Tavily requires an API key');
        }

        $body = [
            'api_key' => $key,
            'query' => $request->query,
            'max_results' => $request->maxResults,
            'include_answer' => $request->includeAnswer,
        ];

        // search_depth 可在渠道 setting 中配置（basic / advanced）
        $searchDepth = $this->getSetting($channel, 'search_depth');
        if ($searchDepth) {
            $body['search_depth'] = $searchDepth;
        }

        if ($request->topic === 'news') {
            $body['topic'] = 'news';
        }
        if (! empty($request->includeDomains)) {
            $body['include_domains'] = $request->includeDomains;
        }
        if (! empty($request->excludeDomains)) {
            $body['exclude_domains'] = $request->excludeDomains;
        }

        $data = $this->httpPost($this->getBaseUrl($channel).'/search', $body);

        $articles = [];
        foreach ($data['results'] ?? [] as $item) {
            $articles[] = new NewsArticle(
                title: $item['title'] ?? null,
                url: $item['url'] ?? null,
                content: $item['content'] ?? null,
                score: isset($item['score']) ? (float) $item['score'] : null,
            );
        }

        return new NewsSearchResult(
            articles: $articles,
            answer: $data['answer'] ?? null,
            totalResults: count($articles),
            provider: $this->getProviderKey(),
            raw: $this->shouldIncludeRaw() ? $data : null,
        );
    }
}
