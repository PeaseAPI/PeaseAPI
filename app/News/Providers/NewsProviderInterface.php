<?php

declare(strict_types=1);

namespace App\News\Providers;

use App\Enums\ChannelType;
use App\Models\Channel;
use App\News\NewsSearchRequest;
use App\News\NewsSearchResult;

/**
 * 新闻 / 搜索 Provider 适配器接口
 *
 * 每个 Provider 负责将归一化的 NewsSearchRequest 转换为上游 API 请求，
 * 并将上游响应归一化为 NewsSearchResult。
 */
interface NewsProviderInterface
{
    /**
     * Provider 标识（如 google_custom_search / news_api / tavily / exa）
     */
    public function getProviderKey(): string;

    /**
     * 该 Provider 对应的渠道类型
     */
    public function getChannelType(): ChannelType;

    /**
     * 执行搜索
     *
     * @throws \RuntimeException 上游请求失败或凭证缺失时抛出
     */
    public function search(NewsSearchRequest $request, Channel $channel): NewsSearchResult;
}
