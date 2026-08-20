<?php

declare(strict_types=1);

namespace App\Relay\Channel;

use App\Relay\Common\RelayInfo;
use App\Relay\Constant\RelayMode;
use Illuminate\Support\Facades\Http;

/**
 * OpenAI 兼容协议 Trait
 * 适用于所有使用 OpenAI 兼容 API 的渠道（DeepSeek, Moonshot, Mistral 等）
 * 提供: 请求构建、HTTP 请求、流式处理、错误处理的标准实现
 */
trait OpenAICompatibleTrait
{
    /**
     * 默认请求路径映射（可被子类覆盖）
     */
    protected function getDefaultPathMap(): array
    {
        return [
            RelayMode::ChatCompletions => '/v1/chat/completions',
            RelayMode::Completions => '/v1/completions',
            RelayMode::Embeddings => '/v1/embeddings',
            RelayMode::ImageGenerations => '/v1/images/generations',
            RelayMode::ImageEdits => '/v1/images/edits',
            RelayMode::AudioTranscriptions => '/v1/audio/transcriptions',
            RelayMode::AudioTranslations => '/v1/audio/translations',
            RelayMode::AudioSpeech => '/v1/audio/speech',
            RelayMode::Rerank => '/v1/rerank',
            RelayMode::Moderations => '/v1/moderations',
            RelayMode::Responses => '/v1/responses',
        ];
    }

    /**
     * 格式化请求（标准 OpenAI 兼容格式）
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

        // 设置流式标记
        $info->isStream = (bool) ($body['stream'] ?? false);
        $info->isImage = in_array($info->relayMode, [
            RelayMode::ImageGenerations,
            RelayMode::ImageEdits,
        ], true);
        $info->isAudio = in_array($info->relayMode, [
            RelayMode::AudioTranscriptions,
            RelayMode::AudioTranslations,
            RelayMode::AudioSpeech,
        ], true);
        $info->isRerank = $info->relayMode === RelayMode::Rerank;

        // 流式选项支持
        if ($info->isStream && $info->supportStreamOptions && ! isset($body['stream_options'])) {
            $body['stream_options'] = ['include_usage' => true];
        }

        // 替换上游模型名
        if ($info->isModelMapped && $info->upstreamModelName) {
            $body['model'] = $info->upstreamModelName;
        }

        $info->requestBody = $body;
    }

    /**
     * 执行上游 HTTP 请求（非流式）
     */
    public function doRequest(RelayInfo $info): void
    {
        if ($info->isStream) {
            return;
        }

        $url = $this->buildRequestUrl($info);
        $headers = $this->buildRequestHeaders($info);
        $timeout = $info->isImage ? 300 : 120;

        $response = Http::withHeaders($headers)
            ->timeout($timeout)
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
        if (! $info->isStream) {
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
     * 错误处理（OpenAI 标准错误格式）
     */
    public function errorHandler(RelayInfo $info): void
    {
        $statusCode = $info->responseStatus ?: 500;
        $errorBody = json_decode($info->responseBody, true);

        // 兼容不同提供商的错误格式
        $errorMessage = $errorBody['error']['message']
            ?? $errorBody['message']
            ?? '上游请求失败';
        $errorCode = $errorBody['error']['code']
            ?? $errorBody['code']
            ?? 'upstream_error';
        $errorType = $errorBody['error']['type']
            ?? $errorBody['type']
            ?? 'upstream_error';

        $response = [
            'error' => [
                'message' => $errorMessage,
                'type' => $errorType,
                'code' => $errorCode,
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
    protected function buildRequestUrl(RelayInfo $info): string
    {
        $pathMap = $this->getDefaultPathMap();
        $path = $pathMap[$info->relayMode] ?? '/v1/chat/completions';

        return $info->getUpstreamUrl($path);
    }

    /**
     * 构造请求头（子类可覆盖以添加额外头）
     *
     * @return array<string, string>
     */
    protected function buildRequestHeaders(RelayInfo $info): array
    {
        $headers = [
            'Authorization' => 'Bearer '.$info->apiKey,
            'Content-Type' => 'application/json',
        ];

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
            $result[] = $key.': '.$value;
        }

        return $result;
    }
}
