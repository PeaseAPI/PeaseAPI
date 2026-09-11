<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\PerfMetric;
use App\Services\OptionService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Redis;
use Symfony\Component\Finder\Finder;

/**
 * 性能监控控制器 - 对标 new-api controller/performance.go
 *
 * 提供系统性能统计、磁盘缓存清理、GC、日志文件管理
 */
class PerformanceController extends Controller
{
    /**
     * 性能监控页面
     */
    public function index()
    {
        return view('admin.performance');
    }

    public function stats(): JsonResponse
    {
        $stats = [
            'memory' => $this->memoryStats(),
            'disk' => $this->diskStats(),
            'opcache' => $this->opcacheStats(),
            'php' => [
                'version' => PHP_VERSION,
                'sapi' => PHP_SAPI,
                'uname' => php_uname(),
                'loadavg' => function_exists('sys_getloadavg') ? sys_getloadavg() : null,
            ],
            'database' => $this->dbStats(),
            'redis' => $this->redisStats(),
        ];

        return $this->success($stats);
    }

    public function summary(): JsonResponse
    {
        $summary = Cache::remember('perf:summary', 60, function () {
            $window = (int) app(OptionService::class)->get('PerformanceRetentionDays', 7);
            // perf_metrics 无 created_at/duration/is_error 列，使用 bucket_ts + 聚合列
            $cutoff = now()->subDays($window)->getTimestamp();
            $base = PerfMetric::where('bucket_ts', '>=', $cutoff);

            $agg = (clone $base)->selectRaw('COALESCE(SUM(request_count), 0) AS total_requests, COALESCE(SUM(total_latency_ms), 0) AS total_latency_ms, COALESCE(SUM(success_count), 0) AS success_count')->first();

            $totalRequests = (int) ($agg->total_requests ?? 0);
            $totalLatency = (int) ($agg->total_latency_ms ?? 0);
            $successCount = (int) ($agg->success_count ?? 0);

            return [
                'window_days' => $window,
                'total_requests' => $totalRequests,
                'avg_duration' => $totalRequests > 0 ? round($totalLatency / $totalRequests, 2) : 0.0,
                'p95_duration' => $this->percentile('total_latency_ms', 'request_count', 95, $cutoff),
                'p99_duration' => $this->percentile('total_latency_ms', 'request_count', 99, $cutoff),
                'error_rate' => $totalRequests > 0 ? round(($totalRequests - $successCount) / $totalRequests * 100, 2) : 0.0,
            ];
        });

        return $this->success($summary);
    }

    public function clearCache(): JsonResponse
    {
        $paths = [
            storage_path('framework/cache/data'),
            storage_path('framework/views'),
            base_path('bootstrap/cache'),
        ];

        $cleared = 0;
        foreach ($paths as $path) {
            if (! is_dir($path)) {
                continue;
            }
            foreach (Finder::create()->in($path)->ignoreDotFiles(false) as $file) {
                if ($file->isFile() && @unlink($file->getRealPath())) {
                    $cleared++;
                }
            }
        }
        Cache::flush();

        return $this->success(['cleared' => $cleared], '磁盘缓存已清理');
    }

    public function resetStats(): JsonResponse
    {
        PerfMetric::truncate();

        return $this->success(null, '性能统计已重置');
    }

    public function forceGc(): JsonResponse
    {
        $before = memory_get_usage(true);
        gc_collect_cycles();
        $after = memory_get_usage(true);

        return $this->success([
            'before' => $before,
            'after' => $after,
            'freed' => $before - $after,
        ], 'GC 已执行');
    }

    public function logs(Request $request): JsonResponse
    {
        $logPath = storage_path('logs');
        $files = [];

        if (is_dir($logPath)) {
            foreach (Finder::create()->files()->in($logPath)->sortByName() as $file) {
                $files[] = [
                    'name' => $file->getFilename(),
                    'size' => $file->getSize(),
                    'modified' => $file->getMTime(),
                ];
            }
        }

        $tail = (int) $request->input('tail', 100);
        $target = $request->input('file', 'laravel.log');
        $content = '';

        $targetFile = $logPath.'/'.basename($target);
        if (is_file($targetFile)) {
            $lines = file($targetFile);
            $content = implode('', array_slice($lines ?: [], -$tail));
        }

        return $this->success([
            'files' => $files,
            'current' => $target,
            'content' => $content,
        ]);
    }

    public function deleteLogs(Request $request): JsonResponse
    {
        $logPath = storage_path('logs');
        $target = $request->input('file');
        $deleted = 0;

        if ($target) {
            $file = $logPath.'/'.basename($target);
            if (is_file($file) && @unlink($file)) {
                $deleted = 1;
            }
        } else {
            foreach (Finder::create()->files()->in($logPath) as $file) {
                if (@unlink($file->getRealPath())) {
                    $deleted++;
                }
            }
        }

        return $this->success(['deleted' => $deleted], '日志已清理');
    }

    protected function memoryStats(): array
    {
        $usage = memory_get_usage(true);
        $peak = memory_get_peak_usage(true);

        return [
            'usage' => $usage,
            'peak' => $peak,
            'limit' => $this->bytesFromIni(ini_get('memory_limit')),
            'usage_human' => $this->humanSize($usage),
            'peak_human' => $this->humanSize($peak),
        ];
    }

    protected function diskStats(): array
    {
        $path = base_path();
        $total = disk_total_space($path);
        $free = disk_free_space($path);

        return [
            'total' => $total,
            'free' => $free,
            'used' => $total - $free,
            'usage_percent' => $total > 0 ? round(($total - $free) / $total * 100, 2) : 0,
        ];
    }

    protected function opcacheStats(): array
    {
        if (! function_exists('opcache_get_status')) {
            return ['enabled' => false];
        }
        $status = opcache_get_status(false);

        return $status ?: ['enabled' => false];
    }

    protected function dbStats(): array
    {
        try {
            $conn = config('database.default');
            $config = config('database.connections.'.$conn);
            $pdo = DB::connection()->getPdo();

            return [
                'driver' => $config['driver'] ?? $conn,
                'database' => $config['database'] ?? null,
                'server_version' => $pdo->getAttribute(\PDO::ATTR_SERVER_VERSION),
            ];
        } catch (\Throwable $e) {
            return ['error' => $e->getMessage()];
        }
    }

    protected function redisStats(): array
    {
        try {
            $info = Redis::info();

            return [
                'connected' => true,
                'used_memory' => $info['used_memory'] ?? null,
                'used_memory_human' => $info['used_memory_human'] ?? null,
                'connected_clients' => $info['connected_clients'] ?? null,
                'uptime_in_seconds' => $info['uptime_in_seconds'] ?? null,
            ];
        } catch (\Throwable $e) {
            return ['connected' => false, 'error' => $e->getMessage()];
        }
    }

    /**
     * 按桶估算延迟分位数（ms）
     *
     * perf_metrics 为预聚合表（每小时一桶），无法求真实单请求分位数；
     * 以各桶平均延迟（total_latency_ms/request_count）为样本，在 PHP 侧
     * 计算分位数，兼容 MySQL/SQLite（不支持 PERCENTILE_CONT 聚合语法）。
     */
    protected function percentile(string $latencyColumn, string $countColumn, int $p, int $cutoff): float
    {
        $buckets = PerfMetric::where('bucket_ts', '>=', $cutoff)
            ->where($countColumn, '>', 0)
            ->selectRaw("{$latencyColumn} / {$countColumn} AS avg_ms")
            ->pluck('avg_ms');

        if ($buckets->isEmpty()) {
            return 0.0;
        }

        $values = $buckets->map(fn ($v) => (float) $v)->sort()->values();
        $index = (int) ceil($p / 100 * $values->count()) - 1;

        return round($values->get(max(0, $index)), 2);
    }

    protected function bytesFromIni(string|false $value): int
    {
        if (! $value || $value === '-1') {
            return -1;
        }
        $value = trim((string) $value);
        $last = strtolower($value[strlen($value) - 1]);
        $num = (int) $value;
        switch ($last) {
            case 'g': $num *= 1024;
                // no break
            case 'm': $num *= 1024;
                // no break
            case 'k': $num *= 1024;
        }

        return $num;
    }

    protected function humanSize(int $bytes): string
    {
        if ($bytes < 1024) {
            return $bytes.' B';
        }
        $units = ['KB', 'MB', 'GB', 'TB'];
        $size = $bytes / 1024;
        foreach ($units as $unit) {
            if ($size < 1024) {
                return round($size, 2).' '.$unit;
            }
            $size /= 1024;
        }

        return round($size, 2).' PB';
    }
}
