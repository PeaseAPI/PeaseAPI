<?php

declare(strict_types=1);

namespace App\Relay\Channel\Task;

use App\Relay\Channel\BaseAdapter;
use App\Relay\Channel\ChannelAdapterInterface;
use App\Relay\Common\RelayInfo;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Task 异步任务适配器基类
 * 处理视频生成、音乐生成等异步任务
 */
abstract class TaskAdapter extends BaseAdapter implements ChannelAdapterInterface
{
    /**
     * 任务平台标识
     */
    protected string $platform = '';

    /**
     * 提交任务
     */
    public function submitTask(RelayInfo $info): array
    {
        $channel = $info->getChannel();
        $baseUrl = rtrim($channel->base_url ?? '', '/');
        $url = $this->buildSubmitUrl($baseUrl, $info);
        $headers = $this->buildHeaders($info);
        $body = $this->buildRequestBody($info);

        $response = Http::withHeaders($headers)
            ->timeout(60)
            ->post($url, $body);

        return $this->handleSubmitResponse($response, $info);
    }

    /**
     * 查询任务状态
     */
    public function fetchTask(RelayInfo $info): array
    {
        $channel = $info->getChannel();
        $baseUrl = rtrim($channel->base_url ?? '', '/');
        $taskId = $info->getParam('task_id', '');
        $url = $this->buildFetchUrl($baseUrl, $taskId, $info);
        $headers = $this->buildHeaders($info);

        $response = Http::withHeaders($headers)
            ->timeout(30)
            ->get($url);

        return $this->handleFetchResponse($response, $info);
    }

    /**
     * 构建提交 URL
     */
    abstract protected function buildSubmitUrl(string $baseUrl, RelayInfo $info): string;

    /**
     * 构建查询 URL
     */
    abstract protected function buildFetchUrl(string $baseUrl, string $taskId, RelayInfo $info): string;

    /**
     * 构建请求体
     */
    abstract protected function buildRequestBody(RelayInfo $info): array;

    /**
     * 处理提交响应
     */
    protected function handleSubmitResponse(Response $response, RelayInfo $info): array
    {
        if (!$response->successful()) {
            Log::error("Task submit failed", [
                'platform' => $this->platform,
                'status' => $response->status(),
                'body' => $response->body(),
            ]);
            return [
                'success' => false,
                'message' => '任务提交失败: ' . $response->body(),
            ];
        }

        $data = $response->json();
        return $this->parseSubmitResponse($data, $info);
    }

    /**
     * 处理查询响应
     */
    protected function handleFetchResponse(Response $response, RelayInfo $info): array
    {
        if (!$response->successful()) {
            return [
                'success' => false,
                'message' => '任务查询失败',
            ];
        }

        $data = $response->json();
        return $this->parseFetchResponse($data, $info);
    }

    /**
     * 解析提交响应
     */
    protected function parseSubmitResponse(array $data, RelayInfo $info): array
    {
        return [
            'success' => true,
            'task_id' => $data['task_id'] ?? $data['id'] ?? '',
            'status' => 'submitted',
            'data' => $data,
        ];
    }

    /**
     * 解析查询响应
     */
    protected function parseFetchResponse(array $data, RelayInfo $info): array
    {
        return [
            'success' => true,
            'data' => $data,
        ];
    }

    /**
     * 构建请求头
     */
    protected function buildHeaders(RelayInfo $info): array
    {
        $channel = $info->getChannel();
        $apiKey = $this->getApiKey($channel);

        return [
            'Content-Type' => 'application/json',
            'Authorization' => 'Bearer ' . $apiKey,
        ];
    }

    /**
     * 获取 API Key
     */
    protected function getApiKey($channel): string
    {
        $key = $channel->key ?? '';
        // 支持多 key，取第一个
        if (str_contains($key, "\n")) {
            $keys = array_filter(explode("\n", $key));
            return $keys[array_rand($keys)] ?? '';
        }
        return $key;
    }

    // ========== ChannelAdapterInterface 方法实现 ==========

    public function formatRequest(RelayInfo $info): void
    {
        // Task 适配器通常不需要格式转换
    }

    public function formatResponse(RelayInfo $info): void
    {
        // Task 适配器通常不需要格式转换
    }

    public function doRequest(RelayInfo $info): void
    {
        $action = $info->getParam('task_action', 'submit');

        if ($action === 'fetch') {
            $result = $this->fetchTask($info);
        } else {
            $result = $this->submitTask($info);
        }

        $info->setResponseData($result);
    }

    public function doResponse(RelayInfo $info): void
    {
        $data = $info->getResponseData();
        $info->setResponseBody(json_encode($data));
    }

    public function streamHandler(RelayInfo $info): void
    {
        // Task 不支持流式
    }

    public function errorHandler(RelayInfo $info): void
    {
        $error = $info->getError() ?? ['message' => '未知错误'];
        $info->setResponseBody(json_encode([
            'success' => false,
            'message' => $error['message'] ?? '任务处理失败',
        ]));
    }
}