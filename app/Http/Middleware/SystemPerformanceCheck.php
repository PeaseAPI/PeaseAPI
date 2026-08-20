<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Redis;
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
        if (! $this->isEnabled()) {
            return $next($request);
        }

        // 检查系统负载
        if ($this->isSystemOverloaded()) {
            return response()->json([
                'error' => [
                    'message' => __('System is overloaded, please try again later.'),
                    'type' => 'server_error',
                    'code' => 'system_overloaded',
                ],
            ], 503);
        }

        // 检查并发请求数
        if ($this->isConcurrentLimitReached()) {
            return response()->json([
                'error' => [
                    'message' => __('Too many requests, please try again later.'),
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
     *
     * 使用 PHP 内置函数 sys_getloadavg() 获取系统负载，
     * 通过读取 /proc/cpuinfo（Linux）获取 CPU 核心数，
     * 完全不依赖 shell_exec / proc_open，兼容宝塔等禁用危险函数的环境。
     */
    protected function isSystemOverloaded(): bool
    {
        // 获取系统负载（PHP 内置函数，无需 shell_exec）
        $load = $this->getSystemLoad();
        if ($load === null) {
            return false;
        }

        // 获取 CPU 核心数（不使用 shell_exec）
        $cpuCount = $this->getCpuCount();
        $loadPerCpu = $load / $cpuCount;

        // 如果每核心负载超过 2，认为过载
        return $loadPerCpu > 2.0;
    }

    /**
     * 获取系统负载（1 分钟平均）
     *
     * 优先使用 PHP 内置 sys_getloadavg()，它在 Linux/macOS/FreeBSD 上均可用，
     * 无需调用任何 shell 命令。在不支持的平台回退到读取 /proc/loadavg。
     */
    protected function getSystemLoad(): ?float
    {
        // sys_getloadavg() 是 PHP 内置函数，不依赖 shell_exec
        if (function_exists('sys_getloadavg')) {
            $load = @sys_getloadavg();
            if (is_array($load) && isset($load[0])) {
                return (float) $load[0];
            }
        }

        // Linux 回退：读取 /proc/loadavg（无需 shell_exec）
        if (PHP_OS_FAMILY === 'Linux') {
            $output = @file_get_contents('/proc/loadavg');
            if ($output !== false) {
                $parts = explode(' ', $output);

                return (float) $parts[0];
            }
        }

        return null;
    }

    /**
     * 获取 CPU 核心数（不使用 shell_exec）
     *
     * - Linux：读取 /proc/cpuinfo 统计 processor 行数
     * - Windows：读取环境变量 NUMBER_OF_PROCESSORS
     * - 其他（macOS/BSD）：回退到默认值 4
     *
     * 结果会缓存到静态变量，避免每次请求重复读取文件。
     */
    protected function getCpuCount(): int
    {
        static $cpuCount = null;

        if ($cpuCount !== null) {
            return $cpuCount;
        }

        // Linux：读取 /proc/cpuinfo
        if (PHP_OS_FAMILY === 'Linux') {
            $cpuinfo = @file_get_contents('/proc/cpuinfo');
            if ($cpuinfo !== false) {
                $count = substr_count($cpuinfo, 'processor:');
                if ($count > 0) {
                    $cpuCount = $count;

                    return $cpuCount;
                }
            }
        }

        // Windows：环境变量
        if (PHP_OS_FAMILY === 'Windows') {
            $envCount = $_ENV['NUMBER_OF_PROCESSORS'] ?? $_SERVER['NUMBER_OF_PROCESSORS'] ?? null;
            if ($envCount !== null && (int) $envCount > 0) {
                $cpuCount = (int) $envCount;

                return $cpuCount;
            }
        }

        // 其他平台（macOS 等）：使用默认值
        $cpuCount = 4;

        return $cpuCount;
    }

    /**
     * 检查是否达到并发限制
     */
    protected function isConcurrentLimitReached(): bool
    {
        // 使用 Redis 跟踪并发请求数
        try {
            $key = 'pease:concurrent_requests';
            $current = Redis::get($key);

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
        Log::warning('Slow request detected', [
            'method' => $request->method(),
            'uri' => $request->getUri(),
            'response_time_ms' => round($responseTime, 2),
            'ip' => $request->ip(),
        ]);
    }
}
