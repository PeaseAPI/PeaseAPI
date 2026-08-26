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
     * Provider 标识（如 google_custom_search / news_api / tavily / exa / brave_search）
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

    /**
     * 是否为纯新闻 Provider（仅返回新闻内容）
     *
     * 返回 true  -> 走 /news 端点
     * 返回 false -> 走 /search 端点（通用搜索 / AI 搜索）
     */
    public function isNewsOnly(): bool;
}
