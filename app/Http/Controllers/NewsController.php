<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Services\NewsService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * 新闻 / 搜索 API 控制器
 *
 * 独立于 OpenAI 兼容中继路由，使用 /news 前缀。
 *
 * 端点：
 *   POST /news/search      搜索新闻 / 网页
 *   GET  /news/providers   获取可用 Provider 列表
 */
class NewsController extends Controller
{
    public function __construct(
        protected NewsService $newsService,
    ) {}

    /**
     * 新闻 / 网页搜索
     */
    public function search(Request $request): JsonResponse
    {
        $result = $this->newsService->search($request);

        if ($result['success']) {
            return $this->success(
                $result['data'] ?? null,
                $result['message'] ?? __('News search completed')
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
            $result['message'] ?? __('News search failed'),
            $status,
            $result['data'] ?? null
        );
    }

    /**
     * 可用新闻 Provider 列表（仅新闻类，排除搜索 Provider）
     */
    public function providers(): JsonResponse
    {
        $allProviders = $this->newsService->listProviders();

        // 过滤出仅新闻类 Provider
        $newsProviders = array_values(array_filter($allProviders, function ($p) {
            return ($p['is_news_only'] ?? false) === true;
        }));

        return $this->success(
            $newsProviders,
            __('Available news providers')
        );
    }
}
