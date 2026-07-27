<?php

declare(strict_types=1);

namespace App\Relay\Channel\Ali;

use App\Enums\ApiType;
use App\Relay\Channel\BaseAdapter;
use App\Relay\Common\RelayInfo;
use App\Relay\Constant\RelayMode;
use Illuminate\Support\Facades\Http;

/**
 * 阿里通义千问渠道适配器
 * 对标 new-api relay/channel/ali/
 * 支持: Chat (DashScope API)
 * API 文档: https://help.aliyun.com/document_detail/2712195.html
 */
class AliAdapter extends BaseAdapter
{
    protected string $name = 'ali';
    protected int $apiType = ApiType::ALI_DASHSCOPE->value;

    /** @var array<int, string> */
    protected array $supportedActions = [
        RelayMode::ChatCompletions,
    ];

    /**
     * 格式化请求 - 转换 OpenAI 格式为 DashScope 格式
     */
    public function formatRequest(RelayInfo $info): void
    {
        $body = $info->requestBody;

        // 应用参数覆盖
        if (!empty($info->paramOverride)) {
            foreach ($info->paramOverride as $key => $value) {
                $body[$key] = $value;
            }
        }

        $info->isStream = (bool) ($body['stream'] ?? false);

        // 替换上游模型名
        if ($info->isModelMapped && $info->upstreamModelName) {
            $body['model'] = $info->upstreamModelName;
        }

        $info->requestBody = $body;
    }

    /**
     * 执行上游 HTTP 请求
     */
    public function doRequest(RelayInfo $info): void
    {
        if ($info->isStream) {
            return;
        }

        $url = $this->buildRequestUrl($info);
        $headers = $this->buildRequestHeaders($info);

        $response = Http::withHeaders($headers)
            ->timeout(120)
            ->post($url, $info->requestBody);

        $info->responseStatus = $response->status();
        $info->responseBody = $response->body();
        $info->responseHeaders = $response->headers();
    }

    /**
     * 格式化响应
     */
    public function formatResponse(RelayInfo $info): void
    {
        if (!$info->isStream) {
            $body = json_decode($info->responseBody, true);
            if (is_array($body) && isset($body['usage'])) {
                $info->promptTokens = (int) ($body['usage']['prompt_tokens'] ?? 0);
                $info->completionTokens = (int) ($body['usage']['completion_tokens'] ?? 0);
            }
        }
    }

    /**
     * 执行响应输出
     */
    public function doResponse(RelayInfo $info): void
    {
        if ($info->isStream) {
            $this->streamHandler($info);
            return;
        }

        header('Content-Type: application/json');
        http_response_code($info->responseStatus);
        echo $info->responseBody;
    }

    /**
     * 流式处理 (SSE)
     */
    public function streamHandler(RelayInfo $info): void
    {
        $url = $this->buildRequestUrl($info);
        $headers = $this->buildRequestHeaders($info);

        header('Content-Type: text/event-stream');
        header('Cache-Control: no-cache');
        header('Connection: keep-alive');
        header('X-Accel-Buffering: no');

        @ob_end_flush();

        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL => $url,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => json_encode($info->requestBody),
            CURLOPT_HTTPHEADER => $this->formatCurlHeaders($headers),
            CURLOPT_WRITEFUNCTION => function ($curl, $data) use ($info) {
                $info->recordFirstResponse();
                echo $data;
                flush();
                return strlen($data);
            },
            CURLOPT_TIMEOUT => 300,
            CURLOPT_HEADER => false,
        ]);

        curl_exec($ch);
        $info->responseStatus = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
    }

    /**
     * 错误处理
     */
    public function errorHandler(RelayInfo $info): void
    {
        $statusCode = $info->responseStatus ?: 500;
        $errorBody = json_decode($info->responseBody, true);
        $errorMessage = $errorBody['message'] ?? $errorBody['error']['message'] ?? '上游请求失败';

        $response = [
            'error' => [
                'message' => $errorMessage,
                'type' => 'ali_error',
                'code' => $errorBody['code'] ?? 'upstream_error',
                'param' => null,
            ],
        ];

        http_response_code($statusCode);
        header('Content-Type: application/json');
        echo json_encode($response);
    }

    /**
     * 构造请求 URL
     */
    private function buildRequestUrl(RelayInfo $info): string
    {
        $path = '/compatible-mode/v1/chat/completions';
        return $info->getUpstreamUrl($path);
    }

    /**
     * 构造请求头 - DashScope 使用 Bearer token
     *
     * @return array<string, string>
     */
    private function buildRequestHeaders(RelayInfo $info): array
    {
        $headers = [
            'Authorization' => 'Bearer ' . $info->apiKey,
            'Content-Type' => 'application/json',
        ];

        foreach ($info->headersOverride as $key => $value) {
            $headers[$key] = (string) $value;
        }

        return $headers;
    }

    /**
     * 格式化 cURL 头
     *
     * @param array<string, string> $headers
     * @return array<int, string>
     */
    private function formatCurlHeaders(array $headers): array
    {
        $result = [];
        foreach ($headers as $key => $value) {
            $result[] = $key . ': ' . $value;
        }
        return $result;
    }
}