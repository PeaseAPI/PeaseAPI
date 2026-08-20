<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Global Web Rate Limit - 对标 new-api middleware/rate.go GlobalWebRateLimit
 *
 * Web 界面全局限流（基于 IP）
 */
class GlobalWebRateLimit
{
    public function handle(Request $request, Closure $next, int $maxAttempts = 100, int $decaySeconds = 60): Response
    {
        $key = 'web:'.$request->ip();

        $attempts = (int) cache()->get("rate_limit:{$key}", 0);
        if ($attempts >= $maxAttempts) {
            return response()->json([
                'success' => false,
                'message' => __('Too many requests, please try again later.'),
            ], Response::HTTP_TOO_MANY_REQUESTS, [
                'Retry-After' => (string) $decaySeconds,
            ]);
        }

        cache()->put("rate_limit:{$key}", $attempts + 1, $decaySeconds);

        $response = $next($request);
        $response->headers->set('X-RateLimit-Limit', (string) $maxAttempts);
        $response->headers->set('X-RateLimit-Remaining', (string) max(0, $maxAttempts - $attempts - 1));

        return $response;
    }
}
