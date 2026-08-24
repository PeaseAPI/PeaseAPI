<?php

use App\Http\Middleware\AdminAuth;
use App\Http\Middleware\ApiRateLimit;
use App\Http\Middleware\Audit;
use App\Http\Middleware\Cache;
use App\Http\Middleware\Cors;
use App\Http\Middleware\CriticalRateLimit;
use App\Http\Middleware\DecompressRequest;
use App\Http\Middleware\DisableCache;
use App\Http\Middleware\Distributor;
use App\Http\Middleware\EmailVerificationRateLimit;
use App\Http\Middleware\GlobalApiRateLimit;
use App\Http\Middleware\GlobalWebRateLimit;
use App\Http\Middleware\Gzip;
use App\Http\Middleware\HeaderNav;
use App\Http\Middleware\I18n;
use App\Http\Middleware\ModelRateLimit;
use App\Http\Middleware\RequestBodyLimit;
use App\Http\Middleware\RequestId;
use App\Http\Middleware\RootAuth;
use App\Http\Middleware\SearchRateLimit;
use App\Http\Middleware\SecureVerification;
use App\Http\Middleware\SessionCookieOriginGuard;
use App\Http\Middleware\Stats;
use App\Http\Middleware\SystemPerformanceCheck;
use App\Http\Middleware\TokenAuth;
use App\Http\Middleware\TokenAuthReadOnly;
use App\Http\Middleware\TokenOrUserAuth;
use App\Http\Middleware\TryUserAuth;
use App\Http\Middleware\TurnstileCheck;
use App\Http\Middleware\UserAuth;
use Illuminate\Cookie\Middleware\AddQueuedCookiesToResponse;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Support\Facades\Route;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
        then: function () {
            // Load relay routes without /api prefix (for /v1, /mj, /suno etc.)
            Route::middleware('api')
                ->group(base_path('routes/relay.php'));

            // Load news / search aggregation routes (independent from
            // OpenAI-compatible relay - uses /news prefix with its own
            // middleware stack to avoid request-format conflicts)
            Route::middleware('api')
                ->group(base_path('routes/news.php'));
        },
    )
    ->withMiddleware(function (Middleware $middleware) {
        $middleware->api(prepend: [
            AddQueuedCookiesToResponse::class,
        ]);
        $middleware->web(append: [
            I18n::class,
        ]);
        $middleware->alias([
            // 认证/授权
            'admin' => AdminAuth::class,
            'root' => RootAuth::class,
            'token.auth' => TokenAuth::class,
            'token.auth.readonly' => TokenAuthReadOnly::class,
            'token.or.user' => TokenOrUserAuth::class,
            'try.user' => TryUserAuth::class,
            'user.auth' => UserAuth::class,
            // Relay 核心
            'distributor' => Distributor::class,
            // 限流
            'api.rate' => ApiRateLimit::class,
            'model.rate' => ModelRateLimit::class,
            'global.api.rate' => GlobalApiRateLimit::class,
            'global.web.rate' => GlobalWebRateLimit::class,
            'critical.rate' => CriticalRateLimit::class,
            'search.rate' => SearchRateLimit::class,
            'email.verification.rate' => EmailVerificationRateLimit::class,
            // 请求处理
            'cors' => Cors::class,
            'gzip' => Gzip::class,
            'decompress' => DecompressRequest::class,
            'body.limit' => RequestBodyLimit::class,
            'cache' => Cache::class,
            'disable.cache' => DisableCache::class,
            // 通用功能
            'i18n' => I18n::class,
            'turnstile' => TurnstileCheck::class,
            'secure.verification' => SecureVerification::class,
            'session.origin' => SessionCookieOriginGuard::class,
            'request.id' => RequestId::class,
            'header.nav' => HeaderNav::class,
            'audit' => Audit::class,
            'performance' => Stats::class,
            'stats' => Stats::class,
            'system.performance' => SystemPerformanceCheck::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions) {
        //
    })->create();
