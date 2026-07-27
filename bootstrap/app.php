<?php

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
        then: function () {
            // Load relay routes without /api prefix (for /v1, /mj, /suno etc.)
            \Illuminate\Support\Facades\Route::middleware('api')
                ->group(base_path('routes/relay.php'));
        },
    )
    ->withMiddleware(function (Middleware $middleware) {
        $middleware->api(prepend: [
            \Illuminate\Cookie\Middleware\AddQueuedCookiesToResponse::class,
        ]);
        $middleware->web(append: [
            \App\Http\Middleware\I18n::class,
        ]);
        $middleware->alias([
            // 认证/授权
            'admin' => \App\Http\Middleware\AdminAuth::class,
            'root' => \App\Http\Middleware\RootAuth::class,
            'token.auth' => \App\Http\Middleware\TokenAuth::class,
            'token.auth.readonly' => \App\Http\Middleware\TokenAuthReadOnly::class,
            'token.or.user' => \App\Http\Middleware\TokenOrUserAuth::class,
            'try.user' => \App\Http\Middleware\TryUserAuth::class,
            'user.auth' => \App\Http\Middleware\UserAuth::class,
            // Relay 核心
            'distributor' => \App\Http\Middleware\Distributor::class,
            // 限流
            'api.rate' => \App\Http\Middleware\ApiRateLimit::class,
            'model.rate' => \App\Http\Middleware\ModelRateLimit::class,
            'global.api.rate' => \App\Http\Middleware\GlobalApiRateLimit::class,
            'global.web.rate' => \App\Http\Middleware\GlobalWebRateLimit::class,
            'critical.rate' => \App\Http\Middleware\CriticalRateLimit::class,
            'search.rate' => \App\Http\Middleware\SearchRateLimit::class,
            'email.verification.rate' => \App\Http\Middleware\EmailVerificationRateLimit::class,
            // 请求处理
            'cors' => \App\Http\Middleware\Cors::class,
            'gzip' => \App\Http\Middleware\Gzip::class,
            'decompress' => \App\Http\Middleware\DecompressRequest::class,
            'body.limit' => \App\Http\Middleware\RequestBodyLimit::class,
            'cache' => \App\Http\Middleware\Cache::class,
            'disable.cache' => \App\Http\Middleware\DisableCache::class,
            // 通用功能
            'i18n' => \App\Http\Middleware\I18n::class,
            'turnstile' => \App\Http\Middleware\TurnstileCheck::class,
            'secure.verification' => \App\Http\Middleware\SecureVerification::class,
            'session.origin' => \App\Http\Middleware\SessionCookieOriginGuard::class,
            'request.id' => \App\Http\Middleware\RequestId::class,
            'header.nav' => \App\Http\Middleware\HeaderNav::class,
            'audit' => \App\Http\Middleware\Audit::class,
            'performance' => \App\Http\Middleware\Stats::class,
            'stats' => \App\Http\Middleware\Stats::class,
            'system.performance' => \App\Http\Middleware\SystemPerformanceCheck::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions) {
        //
    })->create();