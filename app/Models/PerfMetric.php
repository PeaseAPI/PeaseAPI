<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

/**
 * 性能指标模型
 * 
 * 对标源项目: model/perf_metric.go
 * 存储聚合的中转性能指标，用于模型分析
 * 
 * @property int $id
 * @property string $model_name
 * @property string $group
 * @property int $bucket_ts
 * @property int $request_count
 * @property int $success_count
 * @property int $total_latency_ms
 * @property int $ttft_sum_ms
 * @property int $ttft_count
 * @property int $output_tokens
 * @property int $generation_ms
 */
class PerfMetric extends Model
{
    protected $table = 'perf_metrics';

    public $timestamps = false;

    protected $fillable = [
        'model_name',
        'group',
        'bucket_ts',
        'request_count',
        'success_count',
        'total_latency_ms',
        'ttft_sum_ms',
        'ttft_count',
        'output_tokens',
        'generation_ms',
    ];

    protected $casts = [
        'request_count' => 'integer',
        'success_count' => 'integer',
        'total_latency_ms' => 'integer',
        'ttft_sum_ms' => 'integer',
        'ttft_count' => 'integer',
        'output_tokens' => 'integer',
        'generation_ms' => 'integer',
    ];

    /**
     * 唯一索引列
     */
    protected $uniqueColumns = ['model_name', 'group', 'bucket_ts'];

    /**
     * 插入或更新性能指标 (Upsert)
     */
    public static function upsert(mixed $metric): bool
    {
        if ($metric === null || $metric->request_count === 0) {
            return false;
        }

        return DB::table('perf_metrics')
            ->updateOrInsert(
                [
                    'model_name' => $metric->model_name,
                    'group' => $metric->group,
                    'bucket_ts' => $metric->bucket_ts,
                ],
                [
                    'request_count' => DB::raw('perf_metrics.request_count + ' . (int) $metric->request_count),
                    'success_count' => DB::raw('perf_metrics.success_count + ' . (int) $metric->success_count),
                    'total_latency_ms' => DB::raw('perf_metrics.total_latency_ms + ' . (int) $metric->total_latency_ms),
                    'ttft_sum_ms' => DB::raw('perf_metrics.ttft_sum_ms + ' . (int) $metric->ttft_sum_ms),
                    'ttft_count' => DB::raw('perf_metrics.ttft_count + ' . (int) $metric->ttft_count),
                    'output_tokens' => DB::raw('perf_metrics.output_tokens + ' . (int) $metric->output_tokens),
                    'generation_ms' => DB::raw('perf_metrics.generation_ms + ' . (int) $metric->generation_ms),
                ]
            );
    }

    /**
     * 获取指定模型和组的性能指标
     */
    public static function getMetrics(
        string $modelName,
        string $group,
        int $startTs,
        int $endTs
    ): array {
        $query = static::where('model_name', $modelName)
            ->where('bucket_ts', '>=', $startTs)
            ->where('bucket_ts', '<=', $endTs);

        if ($group !== '') {
            $query->where('group', $group);
        }

        return $query->orderBy('bucket_ts', 'asc')->get()->all();
    }

    /**
     * 获取所有模型的性能指标汇总
     */
    public static function getSummaryAll(
        int $startTs,
        int $endTs,
        ?array $groups = null
    ): array {
        $query = static::query()
            ->selectRaw('
                model_name,
                SUM(request_count) as request_count,
                SUM(success_count) as success_count,
                SUM(total_latency_ms) as total_latency_ms,
                SUM(output_tokens) as output_tokens,
                SUM(generation_ms) as generation_ms
            ')
            ->where('bucket_ts', '>=', $startTs)
            ->where('bucket_ts', '<=', $endTs);

        if ($groups !== null) {
            if (count($groups) === 0) {
                return [];
            }
            $query->whereIn('group', $groups);
        }

        return $query->groupBy('model_name')
            ->havingRaw('SUM(request_count) > 0')
            ->get()
            ->all();
    }

    /**
     * 获取所有模型的性能指标分桶汇总
     */
    public static function getSummaryBucketsAll(
        int $startTs,
        int $endTs,
        ?array $groups = null
    ): array {
        $query = static::query()
            ->selectRaw('
                model_name,
                bucket_ts,
                SUM(request_count) as request_count,
                SUM(success_count) as success_count,
                SUM(total_latency_ms) as total_latency_ms,
                SUM(output_tokens) as output_tokens,
                SUM(generation_ms) as generation_ms
            ')
            ->where('bucket_ts', '>=', $startTs)
            ->where('bucket_ts', '<=', $endTs);

        if ($groups !== null) {
            if (count($groups) === 0) {
                return [];
            }
            $query->whereIn('group', $groups);
        }

        return $query->groupBy('model_name', 'bucket_ts')
            ->havingRaw('SUM(request_count) > 0')
            ->orderBy('bucket_ts', 'asc')
            ->get()
            ->all();
    }

    /**
     * 删除指定时间之前的性能指标
     */
    public static function deleteBefore(int $cutoffTs): int
    {
        if ($cutoffTs <= 0) {
            return 0;
        }

        return static::where('bucket_ts', '<', $cutoffTs)->delete();
    }

    /**
     * 计算开始时间 (往前推 N 小时)
     */
    public static function startTime(int $hours = 24): int
    {
        if ($hours <= 0) {
            $hours = 24;
        }

        return time() - ($hours * 3600);
    }

    /**
     * 获取或创建新的性能指标实例
     */
    public static function make(array $attributes = []): static
    {
        $metric = new static();
        $metric->model_name = $attributes['model_name'] ?? '';
        $metric->group = $attributes['group'] ?? '';
        $metric->bucket_ts = $attributes['bucket_ts'] ?? time();
        $metric->request_count = $attributes['request_count'] ?? 0;
        $metric->success_count = $attributes['success_count'] ?? 0;
        $metric->total_latency_ms = $attributes['total_latency_ms'] ?? 0;
        $metric->ttft_sum_ms = $attributes['ttft_sum_ms'] ?? 0;
        $metric->ttft_count = $attributes['ttft_count'] ?? 0;
        $metric->output_tokens = $attributes['output_tokens'] ?? 0;
        $metric->generation_ms = $attributes['generation_ms'] ?? 0;

        return $metric;
    }
}

/**
 * 性能指标汇总结构 (用于视图)
 */
class PerfMetricSummary
{
    public string $model_name;
    public int $request_count;
    public int $success_count;
    public int $total_latency_ms;
    public int $output_tokens;
    public int $generation_ms;

    public function __construct(array $data = [])
    {
        $this->model_name = $data['model_name'] ?? '';
        $this->request_count = (int) ($data['request_count'] ?? 0);
        $this->success_count = (int) ($data['success_count'] ?? 0);
        $this->total_latency_ms = (int) ($data['total_latency_ms'] ?? 0);
        $this->output_tokens = (int) ($data['output_tokens'] ?? 0);
        $this->generation_ms = (int) ($data['generation_ms'] ?? 0);
    }
}

/**
 * 性能指标分桶汇总结构 (用于视图)
 */
class PerfMetricSummaryBucket
{
    public string $model_name;
    public int $bucket_ts;
    public int $request_count;
    public int $success_count;
    public int $total_latency_ms;
    public int $output_tokens;
    public int $generation_ms;

    public function __construct(array $data = [])
    {
        $this->model_name = $data['model_name'] ?? '';
        $this->bucket_ts = (int) ($data['bucket_ts'] ?? 0);
        $this->request_count = (int) ($data['request_count'] ?? 0);
        $this->success_count = (int) ($data['success_count'] ?? 0);
        $this->total_latency_ms = (int) ($data['total_latency_ms'] ?? 0);
        $this->output_tokens = (int) ($data['output_tokens'] ?? 0);
        $this->generation_ms = (int) ($data['generation_ms'] ?? 0);
    }
}