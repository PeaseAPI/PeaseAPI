<?php

declare(strict_types=1);

namespace App\Relay\Channel;

use App\Relay\Common\RelayInfo;

/**
 * Gemini SSE 兼容流式处理（Gemini API / Vertex AI 共用）
 *
 * 上游为 generateContent 系 SSE（data: {candidates, usageMetadata}），客户端为
 * OpenAI Chat Completions 流式协议——逐 chunk 协议转换后经 $callback（或直接 echo）下发；
 * usageMetadata 贯穿所有 chunk 且累计，取最后一条计费
 * （completion = candidatesTokenCount + thoughtsTokenCount，思考 token 按输出计价）。
 *
 * 断连感知与 OpenAICompatibleTrait::streamHandler 同构：WRITEFUNCTION 检测
 * connection_aborted 即 return 0 中止上游读取；curl_multi 每秒探活兜底思考静默期，
 * 杜绝 php-fpm request_terminate_timeout 硬杀导致计费/退款全部跳过（QA-13 同类）。
 */
trait GeminiCompatibleTrait
{
    public function streamHandler(RelayInfo $info, ?callable $callback = null): void
    {
        $url = $this->buildGeminiStreamUrl($info);
        $headers = $this->buildGeminiStreamHeaders($info);

        header('Content-Type: text/event-stream');
        header('Cache-Control: no-cache');
        header('Connection: keep-alive');
        header('X-Accel-Buffering: no');

        @ob_end_flush();

        $ch = curl_init();
        $lineBuf = ''; // SSE 行缓冲（跨 WRITEFUNCTION 分块的半行）
        curl_setopt_array($ch, [
            CURLOPT_URL => $url,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $info->upstreamBody,
            CURLOPT_HTTPHEADER => $this->formatGeminiHeaders($headers),
            CURLOPT_WRITEFUNCTION => function ($curl, $data) use ($info, $callback, &$lineBuf) {
                // 客户端中断：不再继续读上游（模型可能仍在长时间生成），立即结算已解析 usage
                if (connection_aborted() !== 0) {
                    $info->clientAborted = true;

                    return 0; // 返回值 != 数据长度 → curl 以 CURLE_WRITE_ERROR 中止传输
                }

                $info->recordFirstResponse();

                // 解析 Gemini SSE：usageMetadata 累计计费 + 转换为 OpenAI chunk 下发
                $lineBuf .= $data;
                $lines = explode("\n", $lineBuf);
                $lineBuf = (string) array_pop($lines); // 保留最后一段可能不完整的行

                foreach ($lines as $line) {
                    $line = trim($line);
                    if ($line === '' || ! str_starts_with($line, 'data:')) {
                        continue;
                    }
                    $payload = trim(substr($line, 5));
                    if ($payload === '' || $payload === '[DONE]') {
                        continue;
                    }
                    $chunk = json_decode($payload, true);
                    if (! is_array($chunk)) {
                        continue;
                    }

                    $this->extractGeminiUsage($info, $chunk);

                    $openaiChunk = $this->convertGeminiChunkToOpenAI($info, $chunk);
                    if ($openaiChunk === null) {
                        continue;
                    }

                    if ($callback !== null) {
                        $callback($openaiChunk);
                    } else {
                        echo $openaiChunk;
                        flush();
                    }
                }

                return strlen($data);
            },
            CURLOPT_TIMEOUT => 300,
            CURLOPT_HEADER => false,
        ]);
        // curl_multi 轮询 + 每秒探活：上游静默期（思考模型）也能秒级感知客户端断连并中止读取
        $mh = curl_multi_init();
        curl_multi_add_handle($mh, $ch);

        $completed = $this->pollStreamTransfer($mh, $ch, function () use ($info, $callback, &$lineBuf): bool {
            // 仅在行边界注入，避免撕裂半行导致客户端事件解析失败
            if ($lineBuf === '') {
                $keepalive = ": keepalive\n\n";
                if ($callback !== null) {
                    $callback($keepalive);
                } else {
                    echo $keepalive;
                    flush();
                }
            }

            if (connection_aborted() !== 0) {
                $info->clientAborted = true;

                return false;
            }

            return true;
        });

        curl_multi_remove_handle($mh, $ch);
        curl_multi_close($mh);

        $info->responseStatus = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if (! $completed) {
            // 已收到响应头，标记成功以便按已解析 usage 结算（对齐 OpenAICompatibleTrait）
            $info->responseStatus = $info->responseStatus ?: 200;
        }

        if ($completed && ! $info->clientAborted) {
            $done = "data: [DONE]\n\n";
            if ($callback !== null) {
                $callback($done);
            } else {
                echo $done;
                flush();
            }
        }
    }

    /**
     * 构造流式上游 URL（含 alt=sse 与认证参数）
     */
    abstract protected function buildGeminiStreamUrl(RelayInfo $info): string;

    /**
     * 构造流式请求头
     *
     * @return array<string, string>
     */
    abstract protected function buildGeminiStreamHeaders(RelayInfo $info): array;

    /**
     * OpenAI messages → Gemini body（contents + systemInstruction + generationConfig）
     *
     * system/developer 消息归入 systemInstruction（旧实现会把 system 当 model 角色
     * 发给上游导致报错）；多模态数组仅聚合 text 段。
     */
    protected function buildGeminiChatBody(array $body): array
    {
        $contents = [];
        $systemParts = [];

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
                $systemParts[] = ['text' => $content];

                continue;
            }

            $contents[] = [
                'role' => $role === 'assistant' ? 'model' : 'user',
                'parts' => [['text' => $content]],
            ];
        }

        $gemini = ['contents' => $contents];
        if ($systemParts !== []) {
            $gemini['systemInstruction'] = ['parts' => $systemParts];
        }

        $gemini['generationConfig'] = [
            'maxOutputTokens' => $body['max_tokens'] ?? 4096,
            'temperature' => $body['temperature'] ?? 0.7,
            'topP' => $body['top_p'] ?? 0.95,
        ];

        return $gemini;
    }

    /**
     * 提取 usageMetadata 计费计数（prompt / candidates+thoughts / cachedContent）
     *
     * @param  array<string, mixed>  $chunk
     */
    protected function extractGeminiUsage(RelayInfo $info, array $chunk): void
    {
        $usage = $chunk['usageMetadata'] ?? null;
        if (! is_array($usage)) {
            return;
        }

        $prompt = max(0, (int) ($usage['promptTokenCount'] ?? 0));
        // 思考 token 按输出计价（usageMetadata 累计，末条 chunk 为最终值）
        $completion = max(0, (int) ($usage['candidatesTokenCount'] ?? 0))
            + max(0, (int) ($usage['thoughtsTokenCount'] ?? 0));

        $info->promptTokens = $prompt;
        $info->completionTokens = $completion;
        $info->cachedTokens = min(max(0, (int) ($usage['cachedContentTokenCount'] ?? 0)), $prompt);
    }

    /**
     * Gemini SSE chunk → OpenAI 流式 chunk（content 增量 / finish_reason + usage）
     * 纯 usage 中间 chunk 不下发；返回 null 表示无可下发内容
     *
     * @param  array<string, mixed>  $chunk
     */
    protected function convertGeminiChunkToOpenAI(RelayInfo $info, array $chunk): ?string
    {
        $text = '';
        $finishReason = null;

        foreach ($chunk['candidates'] ?? [] as $candidate) {
            if (! is_array($candidate)) {
                continue;
            }
            foreach ($candidate['content']['parts'] ?? [] as $part) {
                if (is_array($part) && isset($part['text']) && is_string($part['text'])) {
                    $text .= $part['text'];
                }
            }
            if ($finishReason === null && isset($candidate['finishReason']) && is_string($candidate['finishReason'])) {
                $finishReason = $candidate['finishReason'];
            }
        }

        if ($text === '' && $finishReason === null) {
            return null;
        }

        $payload = [
            'choices' => [[
                'index' => 0,
                'delta' => $text !== '' ? ['content' => $text] : [],
                'finish_reason' => $finishReason !== null ? $this->mapGeminiFinishReason($finishReason) : null,
            ]],
        ];

        if ($finishReason !== null) {
            $payload['usage'] = [
                'prompt_tokens' => $info->promptTokens,
                'completion_tokens' => $info->completionTokens,
            ];
        }

        return 'data: '.json_encode($payload, JSON_UNESCAPED_UNICODE)."\n\n";
    }

    /**
     * Gemini finishReason → OpenAI finish_reason
     */
    protected function mapGeminiFinishReason(string $reason): string
    {
        return match ($reason) {
            'MAX_TOKENS' => 'length',
            'SAFETY', 'RECITATION', 'BLOCKLIST', 'PROHIBITED_CONTENT', 'SPII' => 'content_filter',
            default => 'stop', // STOP 及其余
        };
    }

    /**
     * 非流式响应 → OpenAI chat.completion（全部文本 part 聚合 + usageMetadata 计费）
     */
    protected function formatGeminiCompatibleResponse(RelayInfo $info): void
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

        if (is_array($body) && ! empty($body['candidates'])) {
            $text = '';
            $finishReason = null;
            foreach ($body['candidates'] as $candidate) {
                if (! is_array($candidate)) {
                    continue;
                }
                foreach ($candidate['content']['parts'] ?? [] as $part) {
                    if (is_array($part) && isset($part['text']) && is_string($part['text'])) {
                        $text .= $part['text'];
                    }
                }
                if ($finishReason === null
                    && isset($candidate['finishReason'])
                    && is_string($candidate['finishReason'])) {
                    $finishReason = $candidate['finishReason'];
                }
            }

            $openai['choices'][] = [
                'index' => 0,
                'message' => ['role' => 'assistant', 'content' => $text],
                'finish_reason' => $finishReason !== null ? $this->mapGeminiFinishReason($finishReason) : 'stop',
            ];

            if (isset($body['usageMetadata']) && is_array($body['usageMetadata'])) {
                $this->extractGeminiUsage($info, $body);
            }
        }

        $info->responseBody = json_encode($openai, JSON_UNESCAPED_UNICODE);
    }

    /**
     * 上游错误（{error:{code,message,status}}）→ OpenAI 标准错误格式。
     * 不直接 echo/header：由 RelayHandler 返回 responseBody、控制器统一输出。
     */
    protected function formatGeminiCompatibleError(RelayInfo $info): void
    {
        $statusCode = $info->responseStatus ?: 500;
        $errorBody = json_decode((string) $info->responseBody, true);

        $errorMessage = is_array($errorBody) ? ($errorBody['error']['message'] ?? null) : null;
        $errorStatus = is_array($errorBody) ? ($errorBody['error']['status'] ?? null) : null;

        $info->responseStatus = $statusCode;
        $info->responseBody = json_encode([
            'error' => [
                'message' => is_string($errorMessage) && $errorMessage !== '' ? $errorMessage : '上游请求失败',
                'type' => is_string($errorStatus) && $errorStatus !== '' ? $errorStatus : 'upstream_error',
                'code' => is_string($errorStatus) && $errorStatus !== '' ? $errorStatus : 'upstream_error',
                'param' => null,
            ],
        ], JSON_UNESCAPED_UNICODE);
    }

    /**
     * @param  array<string, string>  $headers
     * @return array<int, string>
     */
    private function formatGeminiHeaders(array $headers): array
    {
        $result = [];
        foreach ($headers as $key => $value) {
            $result[] = "{$key}: {$value}";
        }

        return $result;
    }
}
