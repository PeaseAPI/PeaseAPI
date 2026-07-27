<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use App\Models\PerfMetric;
use App\Models\Option;

/**
 * 性能指标统计中间件
 * 
 * 对标源项目: middleware/stats.go
 * 记录中转请求的性能指标数据
 */
class Stats
{
    /**
     * 处理传入的请求
     */
    public function handle(Request $request, Closure $next): Response
    {
        $startTime = microtime(true);
        $startMemory = memory_get_usage(true);
        
        // 记录请求开始时间到请求属性
        $request->attributes->set('stats_start_time', $startTime);
        $request->attributes->set('stats_start_memory', $startMemory);
        $request->attributes->set('stats_start_ts', time());
        
        // 添加请求开始时间戳
        $request->attributes->set('req_start_time', $startTime);

        $response = $next($request);

        // 计算耗时和内存
        $endTime = microtime(true);
        $endMemory = memory_get_usage(true);
        $latencyMs = (int)(($endTime - $startTime) * 1000);
        $memoryUsed = $endMemory - $startMemory;

        // 存储响应时间供后续使用
        $request->attributes->set('response_time', $latencyMs);
        $request->attributes->set('stats_response_time', $latencyMs);
        $request->attributes->set('stats_memory_used', $memoryUsed);

        // 检查是否启用性能指标收集
        $this->collectMetrics($request, $latencyMs, $endTime);

        return $response;
    }

    /**
     * 收集性能指标
     */
    protected function collectMetrics(Request $request, int $latencyMs, float $endTime): void
    {
        // 检查是否启用性能指标
        $enabled = Option::get('PerformanceMetricEnabled', false);
        if (!$enabled) {
            return;
        }

        // 只统计中转相关请求
        $path = $request->path();
        if (!$this->shouldCollect($path)) {
            return;
        }

        // 获取模型名称
        $modelName = $this->getModelName($request);
        if (empty($modelName)) {
            return;
        }

        // 获取用户分组
        $group = $this->getUserGroup($request);
        
        // 计算生成时间 (TTFT - Time To First Token)
        $ttftMs = $this->calculateTTFT($request, $endTime);
        
        // 判断是否成功响应
        $isSuccess = $this->isSuccessResponse($request);
        
        // 获取输出 token 数
        $outputTokens = $this->getOutputTokens($request);

        // 创建性能指标
        $metric = PerfMetric::make([
            'model_name' => $modelName,
            'group' => $group,
            'bucket_ts' => $this->getBucketTs(),
            'request_count' => 1,
            'success_count' => $isSuccess ? 1 : 0,
            'total_latency_ms' => $latencyMs,
            'ttft_sum_ms' => $ttftMs,
            'ttft_count' => $ttftMs > 0 ? 1 : 0,
            'output_tokens' => $outputTokens,
            'generation_ms' => $latencyMs > $ttftMs ? $latencyMs - $ttftMs : 0,
        ]);

        // 写入性能指标
        PerfMetric::upsert($metric);
    }

    /**
     * 判断是否应该收集指标
     */
    protected function shouldCollect(string $path): bool
    {
        // 只收集中转相关的性能指标
        $relayPaths = [
            'v1/chat/completions',
            'v1/completions',
            'v1/responses',
            'v1/models',
            'v1beta/models',
            'v1/messages',
            'v1/embeddings',
            'v1/images/generations',
            'mj/',
            'suno/',
            'kling/',
            'jimeng/',
        ];

        foreach ($relayPaths as $prefix) {
            if (str_starts_with($path, $prefix)) {
                return true;
            }
        }

        return false;
    }

    /**
     * 获取模型名称
     */
    protected function getModelName(Request $request): string
    {
        // 从路由参数获取模型
        $model = $request->route('model');
        if (!empty($model)) {
            return $model;
        }

        // 从请求体获取模型
        $requestData = $request->all();
        
        if (isset($requestData['model'])) {
            return $requestData['model'];
        }

        if (isset($requestData['engine'])) {
            return $requestData['engine'];
        }

        // 从 URL 路径尝试提取模型
        $path = $request->path();
        if (preg_match('#/v1/models/([^/]+)#', $path, $matches)) {
            return $matches[1];
        }

        return '';
    }

    /**
     * 获取用户分组
     */
    protected function getUserGroup(Request $request): string
    {
        // 从认证用户获取分组
        $user = $request->user();
        if ($user !== null && isset($user->group)) {
            return $user->group;
        }

        // 从 token 获取分组
        $token = $request->attributes->get('token');
        if ($token !== null && isset($token->group)) {
            return $token->group;
        }

        return 'default';
    }

    /**
     * 计算首 token 时间 (TTFT)
     */
    protected function calculateTTFT(Request $request, float $endTime): int
    {
        // 检查是否是流式响应
        $reqStartTime = $request->attributes->get('req_start_time');
        if ($reqStartTime === null) {
            return 0;
        }

        // 判断是否使用了流式响应
        $accept = $request->header('Accept', '');
        if (!str_contains($accept, 'text/event-stream')) {
            return 0;
        }

        // 如果是流式响应，估算 TTFT
        // 在实际流式响应中，应该在首个 token 到达时记录时间
        // 这里做一个简化处理：如果是流式请求，返回总延迟作为近似值
        $streamStartTime = $request->attributes->get('stream_start_time');
        if ($streamStartTime !== null) {
            return (int)(($endTime - $streamStartTime) * 1000);
        }

        // 默认返回 0，实际应该由流式处理器设置
        return 0;
    }

    /**
     * 判断是否成功响应
     */
    protected function isSuccessResponse(Request $request): bool
    {
        // 检查响应状态码
        $response = $request->attributes->get('stats_response');
        if ($response !== null && $response instanceof Response) {
            $statusCode = $response->getStatusCode();
            return $statusCode >= 200 && $statusCode < 400;
        }

        // 检查请求中是否有错误标识
        $error = $request->attributes->get('relay_error');
        if ($error !== null) {
            return false;
        }

        return true;
    }

    /**
     * 获取输出 token 数
     */
    protected function getOutputTokens(Request $request): int
    {
        // 从请求属性获取
        $outputTokens = $request->attributes->get('output_tokens');
        if ($outputTokens !== null) {
            return (int) $outputTokens;
        }

        return 0;
    }

    /**
     * 获取分桶时间戳 (按小时分桶)
     */
    protected function getBucketTs(): int
    {
        $now = time();
        // 向下取整到小时
        return (int)(floor($now / 3600) * 3600);
    }
}
