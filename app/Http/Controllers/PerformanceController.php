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

            return [
                'window_days' => $window,
                'total_requests' => PerfMetric::where('created_at', '>=', now()->subDays($window))->count(),
                'avg_duration' => (float) PerfMetric::where('created_at', '>=', now()->subDays($window))->avg('duration'),
                'p95_duration' => $this->percentile('duration', 95, $window),
                'p99_duration' => $this->percentile('duration', 99, $window),
                'error_rate' => $this->errorRate($window),
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

    protected function percentile(string $column, int $p, int $window): float
    {
        $row = PerfMetric::where('created_at', '>=', now()->subDays($window))
            ->selectRaw("PERCENTILE_CONT(0.{$p}) WITHIN GROUP (ORDER BY {$column}) AS pct")
            ->first();

        return $row ? (float) $row->pct : 0.0;
    }

    protected function errorRate(int $window): float
    {
        $total = PerfMetric::where('created_at', '>=', now()->subDays($window))->count();
        if ($total === 0) {
            return 0.0;
        }
        $errors = PerfMetric::where('created_at', '>=', now()->subDays($window))
            ->where('is_error', true)
            ->count();

        return round($errors / $total * 100, 2);
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
