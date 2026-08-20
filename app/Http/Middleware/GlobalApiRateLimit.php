<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Global API Rate Limit - 对标 new-api middleware/rate.go GlobalAPIRateLimit
 *
 * API 接口全局限流（基于 API Key 或 IP）
 */
class GlobalApiRateLimit
{
    public function handle(Request $request, Closure $next, int $maxAttempts = 60, int $decaySeconds = 60): Response
    {
        $key = $this->resolveRequestSignature($request);
        $cacheKey = "rate_limit:{$key}";

        $attempts = (int) cache()->get($cacheKey, 0);
        if ($attempts >= $maxAttempts) {
            return response()->json([
                'error' => [
                    'message' => __('Too many requests, please try again later.'),
                    'type' => 'rate_limit_error',
                    'code' => 'rate_limit_exceeded',
                ],
            ], Response::HTTP_TOO_MANY_REQUESTS, [
                'Retry-After' => (string) $decaySeconds,
                'X-RateLimit-Limit' => (string) $maxAttempts,
                'X-RateLimit-Remaining' => '0',
            ]);
        }

        cache()->put($cacheKey, $attempts + 1, $decaySeconds);

        $response = $next($request);
        $response->headers->set('X-RateLimit-Limit', (string) $maxAttempts);
        $response->headers->set('X-RateLimit-Remaining', (string) max(0, $maxAttempts - $attempts - 1));

        return $response;
    }

    protected function resolveRequestSignature(Request $request): string
    {
        $apiKey = $request->header('Authorization', '');
        if (str_starts_with($apiKey, 'Bearer ')) {
            return 'api:'.md5(substr($apiKey, 7, 32));
        }

        $userId = $request->attributes->get('api_user_id', 0);
        if ($userId) {
            return "user:{$userId}";
        }

        return 'ip:'.$request->ip();
    }
}
