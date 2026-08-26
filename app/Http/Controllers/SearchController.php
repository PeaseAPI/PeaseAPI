<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Services\NewsService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * 网页搜索 API 控制器
 *
 * 独立于新闻路由，使用 /search 前缀。
 * 仅路由到搜索类 Provider（Tavily、Exa、Brave Search、Google CSE）。
 *
 * 端点：
 *   POST /search/search      搜索网页 / 通用搜索
 *   GET  /search/providers   获取可用搜索 Provider 列表
 */
class SearchController extends Controller
{
    public function __construct(
        protected NewsService $newsService,
    ) {}

    /**
     * 网页 / 通用搜索
     */
    public function search(Request $request): JsonResponse
    {
        $result = $this->newsService->searchWeb($request);

        if ($result['success']) {
            return $this->success(
                $result['data'] ?? null,
                $result['message'] ?? __('Search completed')
            );
        }

        // 错误码 -> HTTP 状态码映射
        $status = match ($result['code'] ?? null) {
            'invalid_request' => 400,
            'quota_exceeded' => 403,
            'no_channel' => 503,
            'upstream_error' => 502,
            default => 500,
        };

        return $this->error(
            $result['message'] ?? __('Search failed'),
            $status,
            $result['data'] ?? null
        );
    }

    /**
     * 可用搜索 Provider 列表（仅搜索类，排除纯新闻 Provider）
     */
    public function providers(): JsonResponse
    {
        $allProviders = $this->newsService->listProviders();

        // 过滤出仅搜索类 Provider
        $searchProviders = array_values(array_filter($allProviders, function ($p) {
            return ($p['is_news_only'] ?? false) === false;
        }));

        return $this->success(
            $searchProviders,
            __('Available search providers')
        );
    }
}
