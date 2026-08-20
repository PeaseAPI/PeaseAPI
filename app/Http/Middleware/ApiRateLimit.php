<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * API Rate Limit Middleware - 对标 new-api middleware/rate.go
 *
 * 基于 Redis 的滑动窗口限流
 */
class ApiRateLimit
{
    protected int $maxAttempts;

    protected int $decaySeconds;

    public function __construct()
    {
        $this->maxAttempts = (int) config('pease-api.rate_limit.max_attempts', 60);
        $this->decaySeconds = (int) config('pease-api.rate_limit.decay_seconds', 60);
    }

    public function handle(Request $request, Closure $next, int $maxAttempts = 60, int $decaySeconds = 60): Response
    {
        $maxAttempts = $maxAttempts ?: $this->maxAttempts;
        $decaySeconds = $decaySeconds ?: $this->decaySeconds;

        // 获取客户端标识
        $key = $this->resolveRequestSignature($request);

        // 检查限流
        if ($this->tooManyAttempts($key, $maxAttempts)) {
            $retryAfter = $this->availableAt($key) - time();

            return response()->json([
                'error' => [
                    'message' => __('Too many requests, please try again later.'),
                    'type' => 'rate_limit_error',
                    'code' => 'rate_limit_exceeded',
                ],
            ], Response::HTTP_TOO_MANY_REQUESTS, [
                'Retry-After' => (string) $retryAfter,
                'X-RateLimit-Limit' => (string) $maxAttempts,
                'X-RateLimit-Remaining' => '0',
                'X-RateLimit-Reset' => (string) $this->availableAt($key),
            ]);
        }

        // 记录请求
        $this->hit($key, $decaySeconds);

        // 添加限流头到响应
        $response = $next($request);
        $response->headers->set('X-RateLimit-Limit', (string) $maxAttempts);
        $response->headers->set('X-RateLimit-Remaining', (string) $this->remaining($key, $maxAttempts));
        $response->headers->set('X-RateLimit-Reset', (string) $this->availableAt($key));

        return $response;
    }

    /**
     * 解析请求签名
     */
    protected function resolveRequestSignature(Request $request): string
    {
        // 优先使用 API Key
        $apiKey = $request->header('Authorization', '');
        if (str_starts_with($apiKey, 'Bearer ')) {
            return 'api:'.md5(substr($apiKey, 7, 32));
        }

        // 使用用户 ID
        $userId = $request->attributes->get('api_user_id', 0);
        if ($userId) {
            return "user:{$userId}";
        }

        // 使用 IP
        return 'ip:'.$request->ip();
    }

    /**
     * 检查是否超过限流
     */
    protected function tooManyAttempts(string $key, int $maxAttempts): bool
    {
        return $this->attempts($key) >= $maxAttempts;
    }

    /**
     * 获取已尝试次数
     */
    protected function attempts(string $key): int
    {
        return (int) cache()->get("rate_limit:{$key}", 0);
    }

    /**
     * 记录请求
     */
    protected function hit(string $key, int $decaySeconds): void
    {
        $key = "rate_limit:{$key}";
        $current = (int) cache()->get($key, 0);
        cache()->put($key, $current + 1, $decaySeconds);
    }

    /**
     * 获取剩余次数
     */
    protected function remaining(string $key, int $maxAttempts): int
    {
        $attempts = $this->attempts($key);

        return max(0, $maxAttempts - $attempts);
    }

    /**
     * 获取可用时间戳
     */
    protected function availableAt(string $key): int
    {
        $ttl = cache()->ttl("rate_limit:{$key}");

        return $ttl > 0 ? time() + $ttl : time() + 60;
    }
}
