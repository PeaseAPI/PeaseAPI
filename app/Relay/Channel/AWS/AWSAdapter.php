<?php

declare(strict_types=1);

namespace App\Relay\Channel\AWS;

use App\Enums\ApiType;
use App\Relay\Channel\BaseAdapter;
use App\Relay\Common\RelayInfo;

/**
 * AWS Bedrock 适配器（Converse API）
 *
 * - 入站 OpenAI Chat Completions → Bedrock Converse 协议转换（system/developer → system
 *   字段、content 字符串/多模态数组聚合 text 段 → [{text}]、inferenceConfig 计参）
 * - 非流式：POST /model/{modelId}/converse（base_url 可覆盖网关根，默认
 *   bedrock-runtime.us-east-1.amazonaws.com；第三方 Bedrock 网关以 Bearer key 鉴权），
 *   响应 output.message.content 聚合 + usage 计费（inputTokens/outputTokens/cacheReadInputTokens）
 * - 流式：Bedrock converse-stream 为 AWS 二进制事件流协议（非 SSE），未实现——
 *   继承 BaseAdapter::streamHandler 显式抛错（QA-22），handleStream 捕获后退款+错误事件；
 *   未来实现时必须复用 BaseAdapter::pollStreamTransfer（断连感知，QA-13 同构）
 */
class AWSAdapter extends BaseAdapter
{
    protected string $name = 'aws';

    protected int $apiType = ApiType::ANTHROPIC->value;

    public function formatRequest(RelayInfo $info): void
    {
        $body = $info->requestBody;
        $info->isStream = (bool) ($body['stream'] ?? false);

        $modelId = $info->upstreamModelName !== ''
            ? $info->upstreamModelName
            : (string) ($body['model'] ?? 'anthropic.claude-3-sonnet-20240229-v1:0');

        $info->upstreamBody = json_encode($this->buildConverseBody($body));
        $info->upstreamUrl = $info->getUpstreamUrl('/model/'.rawurlencode($modelId).'/converse');
    }

    public function doRequest(RelayInfo $info): void
    {
        if ($info->isStream) {
            return; // 流式由 streamHandler 处理（对齐 GeminiAdapter 结构）
        }

        $ch = curl_init($info->upstreamUrl);
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $info->upstreamBody,
            CURLOPT_HTTPHEADER => $this->buildHeaders([
                'Content-Type' => 'application/json',
                'Accept' => 'application/json',
                'Authorization' => 'Bearer '.$this->resolveApiKey($info),
            ]),
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 300,
        ]);

        $result = curl_exec($ch);
        $info->responseStatus = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        // 网络层失败（含 upstreamUrl 为空的配置错误）：显式抛错让 handle 捕获退款并返回
        // 500 JSON，不允许静默产出 200 空响应计 0 费（QA-20/22 同类哲学）
        if ($result === false || $info->responseStatus === 0) {
            throw new \RuntimeException($info->upstreamUrl === ''
                ? 'Bedrock 上游 URL 为空（渠道 base_url 未配置）'
                : 'Bedrock 上游请求失败（curl 网络错误）');
        }

        $info->responseBody = (string) $result;
    }

    public function formatResponse(RelayInfo $info): void
    {
        $body = json_decode((string) $info->responseBody, true);

        $model = $info->upstreamModelName !== '' ? $info->upstreamModelName : $info->model;
        $openai = [
            'id' => 'chatcmpl-'.uniqid(),
            'object' => 'chat.completion',
            'created' => time(),
            'model' => $model,
            'choices' => [],
        ];

        if (is_array($body)) {
            $text = '';
            foreach ($body['output']['message']['content'] ?? [] as $part) {
                if (is_array($part) && isset($part['text']) && is_string($part['text'])) {
                    $text .= $part['text'];
                }
            }

            $openai['choices'][] = [
                'index' => 0,
                'message' => ['role' => 'assistant', 'content' => $text],
                'finish_reason' => $this->mapStopReason((string) ($body['stopReason'] ?? '')),
            ];

            $this->extractUsage($info, $body);
        }

        $info->responseBody = json_encode($openai, JSON_UNESCAPED_UNICODE);
    }

    public function errorHandler(RelayInfo $info): void
    {
        $statusCode = $info->responseStatus ?: 500;
        $errorBody = json_decode((string) $info->responseBody, true);

        // Bedrock 错误为 {message: "..."} 或 {__type: "...Exception", message: "..."}
        $message = is_array($errorBody)
            ? ($errorBody['message'] ?? $errorBody['__type'] ?? null)
            : null;

        $info->responseStatus = $statusCode;
        $info->responseBody = json_encode([
            'error' => [
                'message' => is_string($message) && $message !== '' ? $message : '上游请求失败',
                'type' => 'upstream_error',
                'code' => 'upstream_error',
                'param' => null,
            ],
        ], JSON_UNESCAPED_UNICODE);
    }

    /**
     * OpenAI messages → Bedrock Converse 请求体
     * （system/developer → system 字段；content 字符串/多模态数组聚合 text 段 → [{text}]）
     *
     * @param  array<string, mixed>  $body
     * @return array<string, mixed>
     */
    protected function buildConverseBody(array $body): array
    {
        $messages = [];
        $system = [];

        foreach ($body['messages'] ?? [] as $msg) {
            $role = is_array($msg) ? (string) ($msg['role'] ?? 'user') : 'user';
            $content = is_array($msg) ? ($msg['content'] ?? '') : '';

            if (is_array($content)) {
                $text = '';
                foreach ($content as $part) {
                    if (is_array($part) && ($part['type'] ?? '') === 'text'
                        && isset($part['text']) && is_string($part['text'])) {
                        $text .= $part['text'];
                    }
                }
                $content = $text;
            }

            if (! is_string($content) || $content === '') {
                continue;
            }

            if ($role === 'system' || $role === 'developer') {
                $system[] = ['text' => $content];

                continue;
            }

            $messages[] = [
                'role' => $role === 'assistant' ? 'assistant' : 'user',
                'content' => [['text' => $content]],
            ];
        }

        $converse = ['messages' => $messages];
        if ($system !== []) {
            $converse['system'] = $system;
        }

        $converse['inferenceConfig'] = [
            'maxTokens' => $body['max_tokens'] ?? 4096,
            'temperature' => $body['temperature'] ?? 0.7,
            'topP' => $body['top_p'] ?? 0.95,
        ];

        return $converse;
    }

    /**
     * 提取 Bedrock Converse usage 计费计数
     * （prompt=inputTokens、completion=outputTokens、cached=cacheReadInputTokens 截断到 prompt）
     *
     * @param  array<string, mixed>  $body
     */
    protected function extractUsage(RelayInfo $info, array $body): void
    {
        $usage = $body['usage'] ?? null;
        if (! is_array($usage)) {
            return;
        }

        $prompt = max(0, (int) ($usage['inputTokens'] ?? 0));
        $info->promptTokens = $prompt;
        $info->completionTokens = max(0, (int) ($usage['outputTokens'] ?? 0));
        $info->cachedTokens = min(max(0, (int) ($usage['cacheReadInputTokens'] ?? 0)), $prompt);
    }

    /**
     * Bedrock stopReason → OpenAI finish_reason
     */
    protected function mapStopReason(string $reason): string
    {
        return match ($reason) {
            'max_tokens' => 'length',
            'content_filtered' => 'content_filter',
            default => 'stop', // end_turn / stop_sequence 及其余
        };
    }

    private function resolveApiKey(RelayInfo $info): string
    {
        return $info->apiKey !== '' ? $info->apiKey : (string) ($info->channel->key ?? '');
    }
}
