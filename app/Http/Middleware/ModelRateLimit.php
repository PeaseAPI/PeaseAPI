<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Services\OptionService;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Model Request Rate Limit - 对标 new-api middleware/modelRateLimit.go
 *
 * 按模型进行单独的限流控制，参数由系统设置驱动：
 *   - ModelRateLimitEnabled   总开关（默认关闭）
 *   - ModelRateLimitCount     窗口内默认请求上限
 *   - ModelRateLimitDuration  窗口时长（分钟）
 */
class ModelRateLimit
{
    public function handle(Request $request, Closure $next): Response
    {
        if (! OptionService::get('ModelRateLimitEnabled', false)) {
            return $next($request);
        }

        // 从请求属性获取模型名称
        $model = $request->attributes->get('model', '');

        if (! $model) {
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
                        'message' => __('Model :model is being requested too frequently', ['model' => $model]),
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
        $userId = (int) $request->attributes->get('user_id', 0);

        return "model_rate:{$userId}:{$model}";
    }

    protected function getModelLimit(string $model): int
    {
        // 可以从配置获取各模型的限流配置
        $limits = config('pease-api.rate_limit.model_limits', []);

        foreach ($limits as $pattern => $limit) {
            if (is_int($pattern) || $pattern === 'default') {
                continue;
            }
            if (str_starts_with($model, (string) $pattern)) {
                return (int) $limit;
            }
        }

        // 默认限额：系统设置（ModelRateLimitCount，默认 60）
        return (int) OptionService::get('ModelRateLimitCount', 60);
    }

    protected function windowSeconds(): int
    {
        // 窗口时长（分钟），系统设置 ModelRateLimitDuration，默认 60
        return max(60, ((int) OptionService::get('ModelRateLimitDuration', 60)) * 60);
    }

    protected function tooManyAttempts(string $key, int $maxAttempts): bool
    {
        if ($maxAttempts <= 0) {
            return false;
        }

        return (int) cache()->get($key, 0) >= $maxAttempts;
    }

    protected function hit(string $key): void
    {
        $current = (int) cache()->get($key, 0);
        cache()->put($key, $current + 1, $this->windowSeconds());
    }
}
