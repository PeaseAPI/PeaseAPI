<?php

declare(strict_types=1);

namespace App\Relay\Channel\AnthropicNative;

use App\Relay\Channel\BaseAdapter;
use App\Relay\Common\RelayInfo;
use App\Relay\Constant\RelayMode;
use Illuminate\Support\Facades\Http;

/**
 * Anthropic 原生协议透传适配器
 *
 * 当入站请求本身就是 Anthropic Messages API 格式时使用此适配器。
 * 不做任何格式转换，直接将请求原样转发到上游 Anthropic API，
 * 并将响应原样返回给客户端。
 *
 * 这使得用户可以直接使用 Anthropic SDK（如 anthropic-python），
 * 只需将 base_url 指向本服务即可。
 */
class AnthropicNativeAdapter extends BaseAdapter
{
    protected string $name = 'anthropic_native';

    protected int $apiType = 1; // ApiType::ANTHROPIC

    /** @var array<int, string> */
    protected array $supportedActions = [
        RelayMode::ClaudeMessages,
    ];

    /**
     * 格式化请求（原生 Anthropic 格式，仅做参数覆盖和模型映射，不做格式转换）
     */
    public function formatRequest(RelayInfo $info): void
    {
        $body = $info->requestBody;

        // 应用参数覆盖
        if (! empty($info->paramOverride)) {
            foreach ($info->paramOverride as $key => $value) {
                $body[$key] = $value;
            }
        }

        // 设置流式标记（Anthropic 使用 stream: true）
        $info->isStream = (bool) ($body['stream'] ?? false);

        // 替换上游模型名
        if ($info->isModelMapped && $info->upstreamModelName) {
            $body['model'] = $info->upstreamModelName;
        }

        $info->requestBody = $body;

        // 构造上游 URL
        $info->upstreamUrl = $info->getUpstreamUrl('/v1/messages');
    }

    /**
     * 执行上游 HTTP 请求（非流式）
     */
    public function doRequest(RelayInfo $info): void
    {
        if ($info->isStream) {
            return;
        }

        $headers = $this->buildRequestHeaders($info);

        $response = Http::withHeaders($headers)
            ->timeout(120)
            ->post($info->upstreamUrl, $info->requestBody);

        $info->responseStatus = $response->status();
        $info->responseBody = $response->body();
        $info->responseHeaders = $response->headers();
    }

    /**
     * 格式化响应（原生 Anthropic 格式，不转换，仅提取 token 计数）
     */
    public function formatResponse(RelayInfo $info): void
    {
        if (! $info->isStream) {
            $body = json_decode($info->responseBody, true);
            if (is_array($body) && isset($body['usage'])) {
                $info->promptTokens = (int) ($body['usage']['input_tokens'] ?? 0);
                $info->completionTokens = (int) ($body['usage']['output_tokens'] ?? 0);
            }
        }
    }

    /**
     * 输出响应（原生 Anthropic 格式，原样返回）
     */
    public function doResponse(RelayInfo $info): void
    {
        if ($info->isStream) {
            return;
        }

                header('Content-Type: application/json');
        http_response_code($info->responseStatus);
        echo $info->responseBody;
    }

    /**
     * 流式处理 (SSE) — Anthropic 原生 SSE 格式透传
     *
     * Anthropic SSE 事件格式:
     *   event: message_start / content_block_start / content_block_delta /
     *          content_block_stop / message_delta / message_stop
     */
    public function streamHandler(RelayInfo $info): void
    {
        $url = $info->upstreamUrl;
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
     * 错误处理（Anthropic 原生错误格式透传）
     *
     * Anthropic 错误格式:
     * {"type":"error","error":{"type":"invalid_request_error","message":"..."}}
     */
    public function errorHandler(RelayInfo $info): void
    {
        $statusCode = $info->responseStatus ?: 500;
        $errorBody = json_decode($info->responseBody, true);

        // 如果已经是 Anthropic 格式的错误，直接透传
        if (is_array($errorBody) && isset($errorBody['type']) && $errorBody['type'] === 'error') {
            http_response_code($statusCode);
            header('Content-Type: application/json');
            echo $info->responseBody;

            return;
        }

        // 兜底：将未知格式转为 Anthropic 错误格式
        $errorMessage = $errorBody['error']['message']
            ?? $errorBody['message']
            ?? 'Upstream request failed';
        $errorType = $errorBody['error']['type']
            ?? $errorBody['type']
            ?? 'api_error';

        $response = [
            'type' => 'error',
            'error' => [
                'type' => $errorType,
                'message' => $errorMessage,
            ],
        ];

        http_response_code($statusCode);
        header('Content-Type: application/json');
        echo json_encode($response);
    }

    /**
     * 构造请求头（Anthropic 原生格式）
     *
     * @return array<string, string>
     */
    protected function buildRequestHeaders(RelayInfo $info): array
    {
        $headers = [
            'Content-Type' => 'application/json',
            'x-api-key' => $info->apiKey,
            'anthropic-version' => '2023-06-01',
        ];

        // 如果原始请求带有 anthropic-version，使用客户端提供的版本
        if ($info->request) {
            $clientVersion = $info->request->header('anthropic-version');
            if ($clientVersion) {
                $headers['anthropic-version'] = $clientVersion;
            }
        }

        // 应用自定义头覆盖
        foreach ($info->headersOverride as $key => $value) {
            $headers[$key] = (string) $value;
        }

        return $headers;
    }

    /**
     * 格式化 cURL 头
     *
     * @param  array<string, string>  $headers
     * @return array<int, string>
     */
    protected function formatCurlHeaders(array $headers): array
    {
        $result = [];
        foreach ($headers as $key => $value) {
            $result[] = "{$key}: {$value}";
        }

        return $result;
    }
}

