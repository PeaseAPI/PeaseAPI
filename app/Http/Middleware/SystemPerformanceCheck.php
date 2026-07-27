<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * 系统性能检查中间件
 * 对标 new-api middleware/system_performance_check.go
 * 
 * 检查系统性能指标，如果负载过高则返回 503
 */
class SystemPerformanceCheck
{
    /**
     * 最大允许的并发请求数
     */
    protected int $maxConcurrent = 100;

    /**
     * 最大响应时间 (ms)
     */
    protected int $maxResponseTime = 30000;

    /**
     * 处理请求
     */
    public function handle(Request $request, Closure $next): Response
    {
        // 检查是否启用性能检查
        if (!$this->isEnabled()) {
            return $next($request);
        }

        // 检查系统负载
        if ($this->isSystemOverloaded()) {
            return response()->json([
                'error' => [
                    'message' => 'System is overloaded, please try again later.',
                    'type' => 'server_error',
                    'code' => 'system_overloaded',
                ],
            ], 503);
        }

        // 检查并发请求数
        if ($this->isConcurrentLimitReached()) {
            return response()->json([
                'error' => [
                    'message' => 'Too many requests, please try again later.',
                    'type' => 'rate_limit_error',
                    'code' => 'concurrent_limit',
                ],
            ], 429);
        }

        // 添加请求开始时间到请求属性
        $request->attributes->set('request_start_time', microtime(true));

        $response = $next($request);

        // 检查响应时间
        $startTime = $request->attributes->get('request_start_time', 0);
        if ($startTime > 0) {
            $responseTime = (microtime(true) - $startTime) * 1000;
            
            // 如果响应时间过长，记录警告日志
            if ($responseTime > $this->maxResponseTime) {
                $this->logSlowRequest($request, $responseTime);
            }
        }

        return $response;
    }

    /**
     * 检查是否启用性能检查
     */
    protected function isEnabled(): bool
    {
        // 可通过配置启用/禁用
        return config('pease-api.performance_check_enabled', true);
    }

    /**
     * 检查系统是否过载
     */
    protected function isSystemOverloaded(): bool
    {
        // 获取系统负载
        $load = $this->getSystemLoad();
        if ($load === null) {
            return false;
        }

        // 获取 CPU 核心数
        $cpuCount = (int) shell_exec('sysctl -n hw.ncpu 2>/dev/null') ?: 4;
        $loadPerCpu = $load / $cpuCount;

        // 如果每核心负载超过 2，认为过载
        return $loadPerCpu > 2.0;
    }

    /**
     * 获取系统负载
     */
    protected function getSystemLoad(): ?float
    {
        if (PHP_OS_FAMILY === 'Darwin') {
            // macOS 使用 uptime 获取负载
            $output = shell_exec('uptime | grep -oE "load averages: [0-9]+\.[0-9]+" | awk \'{print $3}\'');
            if ($output) {
                return (float) trim($output);
            }
        } elseif (PHP_OS_FAMILY === 'Linux') {
            // Linux 读取 /proc/loadavg
            $output = @file_get_contents('/proc/loadavg');
            if ($output) {
                $parts = explode(' ', $output);
                return (float) $parts[0];
            }
        }

        return null;
    }

    /**
     * 检查是否达到并发限制
     */
    protected function isConcurrentLimitReached(): bool
    {
        // 使用 Redis 跟踪并发请求数
        try {
            $key = 'pease:concurrent_requests';
            $current = \Illuminate\Support\Facades\Redis::get($key);
            
            if ($current !== null && (int) $current > $this->maxConcurrent) {
                return true;
            }
        } catch (\Exception $e) {
            // Redis 不可用时跳过检查
        }

        return false;
    }

    /**
     * 记录慢请求
     */
    protected function logSlowRequest(Request $request, float $responseTime): void
    {
        \Illuminate\Support\Facades\Log::warning('Slow request detected', [
            'method' => $request->method(),
            'uri' => $request->getUri(),
            'response_time_ms' => round($responseTime, 2),
            'ip' => $request->ip(),
        ]);
    }
}