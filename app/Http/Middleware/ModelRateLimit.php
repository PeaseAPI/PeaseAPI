<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Model Request Rate Limit - 对标 new-api middleware/modelRateLimit.go
 * 
 * 按模型进行单独的限流控制
 */
class ModelRateLimit
{
    public function handle(Request $request, Closure $next): Response
    {
        // 从请求属性获取模型名称
        $model = $request->attributes->get('model', '');
        
        if (!$model) {
            // 从请求体解析
            $body = json_decode($request->getContent(), true);
            $model = $body['model'] ?? '';
        }
        
        if ($model) {
            $key = $this->resolveKey($request, $model);
            $maxAttempts = $this->getModelLimit($model);
            
            if ($this->tooManyAttempts($key, $maxAttempts)) {
                return response()->json([
                    'error' => [
                        'message' => "模型 {$model} 请求过于频繁",
                        'type' => 'rate_limit_error',
                        'code' => 'model_rate_limit_exceeded',
                    ],
                ], Response::HTTP_TOO_MANY_REQUESTS);
            }
            
            $this->hit($key);
        }
        
        return $next($request);
    }

    protected function resolveKey(Request $request, string $model): string
    {
        $userId = $request->attributes->get('api_user_id', 0);
        return "model_rate:{$userId}:{$model}";
    }

    protected function getModelLimit(string $model): int
    {
        // 可以从配置获取各模型的限流配置
        $limits = config('pease-api.rate_limit.model_limits', [
            'gpt-4' => 60,
            'gpt-4o' => 120,
            'claude-3' => 60,
            'default' => 180,
        ]);
        
        foreach ($limits as $pattern => $limit) {
            if (str_starts_with($model, $pattern)) {
                return $limit;
            }
        }
        
        return $limits['default'] ?? 180;
    }

    protected function tooManyAttempts(string $key, int $maxAttempts): bool
    {
        return (int) cache()->get($key, 0) >= $maxAttempts;
    }

    protected function hit(string $key): void
    {
        $current = (int) cache()->get($key, 0);
        cache()->put($key, $current + 1, 60);
    }
}