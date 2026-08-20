<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Disable Cache Middleware - 对标 new-api middleware/cache.go DisableCache
 *
 * 禁用页面缓存，设置 no-cache 响应头
 */
class DisableCache
{
    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        $response->headers->set('Cache-Control', 'no-cache, no-store, must-revalidate, private');
        $response->headers->set('Pragma', 'no-cache');
        $response->headers->set('Expires', '0');
        $response->headers->set('X-Accel-Buffering', 'no');

        return $response;
    }
}
