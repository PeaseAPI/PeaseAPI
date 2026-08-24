<?php

/**
 * News / Search Aggregation Routes
 * --------------------------------------------------------------------------
 *
 * 独立于 OpenAI 兼容中继路由（/v1/*）的新闻 / 搜索聚合 API。
 *
 * 为何独立？
 *   - 新闻 / 搜索 API 的请求 / 响应格式与 OpenAI 兼容 API 完全不同，
 *     混在 relay.php 的 /v1 前缀下容易被 Distributor、DecompressRequest
 *     等中间件错误处理而报错。
 *   - 使用独立的 news 前缀与独立中间件栈，彻底避免格式冲突。
 *
 * 端点：
 *   POST /news/search      搜索新闻 / 网页（支持 Google CSE / NewsAPI / Tavily / Exa）
 *   GET  /news/providers    获取可用 Provider 列表
 *
 * 认证：Bearer Token（与 OpenAI 兼容 API 共用令牌体系，但走独立路由）
 */

use App\Http\Controllers\NewsController;
use App\Http\Middleware\ApiRateLimit;
use App\Http\Middleware\Cors;
use App\Http\Middleware\Stats;
use App\Http\Middleware\TokenAuth;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| News / Search API (independent from OpenAI-compatible relay)
|--------------------------------------------------------------------------
|
| 中间件栈仅包含必要的：Stats（性能统计）+ Cors（跨域）+ TokenAuth（令牌认证与配额计费）
| + ApiRateLimit（限流）。不包含 Distributor / DecompressRequest / ModelRateLimit /
| SystemPerformanceCheck 等 relay 专用中间件，避免与 OpenAI 格式请求混淆。
|
*/

$newsMiddleware = [
    Stats::class,
    Cors::class,
    TokenAuth::class,
    ApiRateLimit::class,
];

Route::middleware($newsMiddleware)->prefix('news')->group(function () {
    // 新闻 / 网页搜索
    Route::post('search', [NewsController::class, 'search']);

    // 可用 Provider 列表
    Route::get('providers', [NewsController::class, 'providers']);
});
