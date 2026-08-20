<?php

declare(strict_types=1);

namespace App\Relay\Channel\OpenAI;

use App\Enums\ApiType;
use App\Relay\Channel\BaseAdapter;
use App\Relay\Common\RelayInfo;
use App\Relay\Constant\RelayMode;
use Illuminate\Support\Facades\Http;

/**
 * OpenAI 渠道适配器
 * 对标 new-api relay/channel/openai/
 * 支持: Chat, Completions, Embeddings, Images, Audio, Rerank, Moderations, Responses
 */
class OpenAIAdapter extends BaseAdapter
{
    protected string $name = 'openai';

    protected int $apiType = ApiType::OpenAI;

    /** @var array<int, string> */
    protected array $supportedActions = [
        RelayMode::ChatCompletions,
        RelayMode::Completions,
        RelayMode::Embeddings,
        RelayMode::ImageGenerations,
        RelayMode::ImageEdits,
        RelayMode::AudioTranscriptions,
        RelayMode::AudioTranslations,
        RelayMode::AudioSpeech,
        RelayMode::Rerank,
        RelayMode::Moderations,
        RelayMode::Responses,
        RelayMode::ResponsesCompact,
    ];

    /**
     * 格式化请求
     */
    public function formatRequest(RelayInfo $info): void
    {
        // 应用参数覆盖
        if (! empty($info->paramOverride)) {
            $body = $info->requestBody;
            foreach ($info->paramOverride as $key => $value) {
                $body[$key] = $value;
            }
            $info->requestBody = $body;
        }

        // 设置流式标记
        $info->isStream = (bool) ($info->requestBody['stream'] ?? false);
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
        if ($info->isStream && $info->supportStreamOptions && ! isset($info->requestBody['stream_options'])) {
            $info->requestBody['stream_options'] = ['include_usage' => true];
        }

        // 替换上游模型名
        if ($info->isModelMapped && $info->upstreamModelName) {
            $info->requestBody['model'] = $info->upstreamModelName;
        }
    }

    /**
     * 执行上游 HTTP 请求
     */
    public function doRequest(RelayInfo $info): void
    {
        $url = $this->buildRequestUrl($info);
        $headers = $this->buildRequestHeaders($info);

        if ($info->isStream) {
            // 流式请求由 streamHandler 处理
            return;
        }

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
        // 非流式响应处理 Token 计数
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

        // 非流式：直接输出响应
        $isJson = str_starts_with($info->responseBody, '{') || str_starts_with($info->responseBody, '[');

        header('Content-Type: '.($isJson ? 'application/json' : 'text/plain'));
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

        // 设置 SSE 响应头
        header('Content-Type: text/event-stream');
        header('Cache-Control: no-cache');
        header('Connection: keep-alive');
        header('X-Accel-Buffering: no');

        // 禁用输出缓冲
        @ob_end_flush();

        // 使用 cURL 进行流式请求
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
        $errorMessage = $errorBody['error']['message'] ?? '上游请求失败';
        $errorCode = $errorBody['error']['code'] ?? 'upstream_error';
        $errorType = $errorBody['error']['type'] ?? 'upstream_error';

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
    private function buildRequestUrl(RelayInfo $info): string
    {
        $path = match ($info->relayMode) {
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
            RelayMode::ResponsesCompact => '/v1/responses/compact',
            default => '/v1/chat/completions',
        };

        return $info->getUpstreamUrl($path);
    }

    /**
     * 构造请求头
     *
     * @return array<string, string>
     */
    private function buildRequestHeaders(RelayInfo $info): array
    {
        $headers = [
            'Authorization' => 'Bearer '.$info->apiKey,
            'Content-Type' => 'application/json',
        ];

        if ($info->organization) {
            $headers['OpenAI-Organization'] = $info->organization;
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
    private function formatCurlHeaders(array $headers): array
    {
        $result = [];
        foreach ($headers as $key => $value) {
            $result[] = $key.': '.$value;
        }

        return $result;
    }
}
