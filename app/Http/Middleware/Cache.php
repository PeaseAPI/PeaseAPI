<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Cache Middleware - 对标 new-api middleware/cache.go
 *
 * 对 GET 请求进行页面缓存（基于 Redis），减少数据库查询
 */
class Cache
{
    public function handle(Request $request, Closure $next, int $ttl = 60): Response
    {
        if (! config('pease-api.cache.enabled', false)) {
            return $next($request);
        }

        // 仅缓存 GET/HEAD 请求
        if (! in_array($request->method(), ['GET', 'HEAD'], true)) {
            return $next($request);
        }

        $key = $this->cacheKey($request);

        $cached = cache()->get($key);
        if ($cached !== null) {
            $response = response($cached['content'], $cached['status']);
            foreach ($cached['headers'] as $name => $value) {
                $response->headers->set($name, $value);
            }
            $response->headers->set('X-Cache', 'HIT');

            return $response;
        }

        $response = $next($request);

        if ($response->isSuccessful()) {
            cache()->put($key, [
                'content' => $response->getContent(),
                'status' => $response->getStatusCode(),
                'headers' => $response->headers->allPreservedCase(),
            ], $ttl);
            $response->headers->set('X-Cache', 'MISS');
        }

        return $response;
    }

    protected function cacheKey(Request $request): string
    {
        return 'page:'.md5($request->fullUrl().'|'.$request->header('Accept-Language', ''));
    }
}
