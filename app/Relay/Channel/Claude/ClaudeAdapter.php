<?php

declare(strict_types=1);

namespace App\Relay\Channel\Claude;

use App\Relay\Channel\BaseAdapter;
use App\Relay\Common\RelayInfo;

/**
 * Claude 适配器 - 对标 new-api relay/channel/anthropic.go
 */
class ClaudeAdapter extends BaseAdapter
{
    public function formatRequest(RelayInfo $info): void
    {
        // 转换 OpenAI 格式请求为 Claude Messages 格式
        $body = $info->requestBody;

        $claudeBody = [
            'model' => $this->mapModel($body['model'] ?? 'claude-3-5-sonnet-20241022'),
            'max_tokens' => $body['max_tokens'] ?? 4096,
            'system' => $body['system'] ?? '',
            'messages' => [],
        ];

        // 转换消息格式
        if (! empty($body['messages'])) {
            foreach ($body['messages'] as $msg) {
                $claudeBody['messages'][] = [
                    'role' => $msg['role'] === 'assistant' ? 'assistant' : ($msg['role'] === 'user' ? 'user' : 'assistant'),
                    'content' => $this->formatContent($msg['content']),
                ];
            }
        }

        // Stream
        if (! empty($body['stream'])) {
            $claudeBody['stream'] = true;
        }

        $info->upstreamBody = json_encode($claudeBody);
        $info->upstreamUrl = 'https://api.anthropic.com/v1/messages';
    }

    public function formatResponse(RelayInfo $info): void
    {
        $body = json_decode($info->responseBody, true);

        if (isset($body['type']) && $body['type'] === 'message_delta') {
            return;
        }

        // 转换为 OpenAI 格式
        $openai = [
            'id' => $body['id'] ?? 'chatcmpl-'.uniqid(),
            'object' => 'chat.completion',
            'created' => $body['created_at'] ?? time(),
            'model' => $body['model'] ?? $info->model,
            'choices' => [],
        ];

        if (! empty($body['content'])) {
            foreach ($body['content'] as $content) {
                if ($content['type'] === 'text') {
                    $openai['choices'][] = [
                        'index' => 0,
                        'message' => [
                            'role' => 'assistant',
                            'content' => $content['text'],
                        ],
                        'finish_reason' => $body['stop_reason'] ?? 'stop',
                    ];
                    break;
                }
            }
        }

        // Token usage（同步回填 RelayInfo：转换路径此前从不设置 token 计数，计费恒为 0）
        if (isset($body['usage'])) {
            $inputTokens = (int) ($body['usage']['input_tokens'] ?? 0);
            $openai['usage'] = [
                'prompt_tokens' => $inputTokens,
                'completion_tokens' => $body['usage']['output_tokens'] ?? 0,
                'total_tokens' => $inputTokens + ($body['usage']['output_tokens'] ?? 0),
            ];
            $info->promptTokens = $inputTokens;
            $info->completionTokens = (int) ($body['usage']['output_tokens'] ?? 0);
            $info->cachedTokens = min(
                max(0, (int) ($body['usage']['cache_read_input_tokens'] ?? 0)),
                max(0, $inputTokens)
            );
        }

        $info->responseBody = json_encode($openai);
    }

    public function doRequest(RelayInfo $info): void
    {
        $channel = $info->channel;

        $headers = [
            'Content-Type' => 'application/json',
            'x-api-key' => $channel->key,
            'anthropic-version' => '2023-06-01',
        ];

        if (! empty($channel->anthropic_organization)) {
            $headers['anthropic-dangerous-direct-websocket'] = $channel->anthropic_organization;
        }

        $ch = curl_init($info->upstreamUrl);
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $info->upstreamBody,
            CURLOPT_HTTPHEADER => $this->buildHeaders($headers),
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 120,
        ]);

        $info->responseBody = curl_exec($ch);
        $info->responseStatus = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
    }

    public function doResponse(RelayInfo $info): void
    {
        // 流式响应处理
    }

    public function streamHandler(RelayInfo $info, ?callable $callback = null): void
    {
        $channel = $info->channel;

        $headers = [
            'Content-Type' => 'application/json',
            'x-api-key' => $channel->key,
            'anthropic-version' => '2023-06-01',
        ];

        $ch = curl_init($info->upstreamUrl);
        $lineBuf = ''; // SSE 行缓冲（跨 WRITEFUNCTION 分块的半行），探活回调也需读取
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $info->upstreamBody,
            CURLOPT_HTTPHEADER => $this->buildHeaders($headers),
            CURLOPT_RETURNTRANSFER => false,
            CURLOPT_TIMEOUT => 120,
            CURLOPT_WRITEFUNCTION => function ($ch, $data) use ($info, $callback, &$lineBuf) {
                // 客户端中断：不再继续读上游（模型可能仍在长时间生成），立即结算已解析 usage，
                // 避免脚本被 FPM request_terminate_timeout 硬杀导致计费/退款全部跳过
                if (connection_aborted() !== 0) {
                    $info->clientAborted = true;

                    return 0; // 返回值 != 数据长度 → curl 以 CURLE_WRITE_ERROR 中止传输
                }

                $info->recordFirstResponse();

                // 解析 Anthropic SSE usage 计费计数：message_start → 输入 token（含缓存命中），
                // message_delta → 输出 token；缺失时转换路径流式计费恒为 0
                $lineBuf .= $data;
                $lines = explode("\n", $lineBuf);
                $lineBuf = (string) array_pop($lines); // 保留最后一段可能不完整的行

                $events = [];
                foreach ($lines as $line) {
                    $line = trim($line);
                    if ($line === '' || ! str_starts_with($line, 'data:')) {
                        continue;
                    }
                    $payload = trim(substr($line, 6));
                    if ($payload === '' || $payload === '[DONE]') {
                        continue;
                    }
                    $event = json_decode($payload, true);
                    if (! is_array($event)) {
                        continue;
                    }
                    $events[] = $event;
                    if (($event['type'] ?? '') === 'message_start' && isset($event['message']['usage'])) {
                        $usage = $event['message']['usage'];
                        $info->promptTokens = (int) ($usage['input_tokens'] ?? 0);
                        $info->cachedTokens = min(
                            max(0, (int) ($usage['cache_read_input_tokens'] ?? 0)),
                            max(0, $info->promptTokens)
                        );
                    } elseif (($event['type'] ?? '') === 'message_delta' && isset($event['usage']['output_tokens'])) {
                        $info->completionTokens = (int) $event['usage']['output_tokens'];
                    }
                }

                if ($callback !== null) {
                    // 转换为 OpenAI 流式格式转发（仅内容与结束事件）
                    foreach ($events as $event) {
                        if (($event['type'] ?? '') === 'content_block_delta') {
                            $text = $event['delta']['text'] ?? '';
                            $callback('data: '.json_encode([
                                'choices' => [[
                                    'index' => 0,
                                    'delta' => [
                                        'content' => $text,
                                    ],
                                ]],
                            ])."\n\n");
                        } elseif (($event['type'] ?? '') === 'message_delta') {
                            $callback('data: '.json_encode([
                                'choices' => [[
                                    'index' => 0,
                                    'delta' => [],
                                    'finish_reason' => $event['delta']['stop_reason'] ?? 'stop',
                                ]],
                                'usage' => [
                                    'prompt_tokens' => $info->promptTokens,
                                    'completion_tokens' => $info->completionTokens,
                                ],
                            ])."\n\n");
                        }
                    }
                } else {
                    echo $data;
                    flush();
                }

                return strlen($data);
            },
        ]);

        // curl_multi 轮询 + 每秒探活：上游静默期（思考模型）也能秒级感知客户端断连并中止读取
        $mh = curl_multi_init();
        curl_multi_add_handle($mh, $ch);

        $completed = $this->pollStreamTransfer($mh, $ch, function () use ($info, $callback, &$lineBuf): bool {
            // 仅在行边界注入，避免撕裂半行导致客户端事件解析失败
            if ($lineBuf === '') {
                if ($callback !== null) {
                    $callback(": keepalive\n\n");
                } else {
                    echo ": keepalive\n\n";
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
        curl_close($ch);

        // 客户端已断开时不再发送结束事件
        if ($completed && ! $info->clientAborted) {
            $callback("data: [DONE]\n\n");
        }
    }

    public function errorHandler(RelayInfo $info): void
    {
        // Claude 错误处理
        $body = json_decode($info->responseBody, true);

        if (isset($body['error'])) {
            $info->responseBody = json_encode([
                'error' => [
                    'message' => $body['error']['message'] ?? 'Unknown error',
                    'type' => $body['error']['type'] ?? 'api_error',
                    'code' => $body['error']['type'] ?? 'api_error',
                ],
            ]);
        }
    }

    protected function mapModel(string $model): string
    {
        $map = [
            'gpt-4o' => 'claude-3-5-sonnet-20241022',
            'gpt-4o-mini' => 'claude-3-haiku-20240307',
            'claude-3-opus' => 'claude-3-opus-20240229',
            'claude-3-sonnet' => 'claude-3-sonnet-20240229',
            'claude-3-haiku' => 'claude-3-haiku-20240307',
            'claude-3-5-sonnet' => 'claude-3-5-sonnet-20241022',
        ];

        return $map[$model] ?? $model;
    }

    protected function formatContent(mixed $content): array|string
    {
        if (is_string($content)) {
            return $content;
        }

        if (is_array($content)) {
            $text = '';
            $images = [];

            foreach ($content as $item) {
                if ($item['type'] === 'text') {
                    $text .= $item['text'];
                } elseif ($item['type'] === 'image_url') {
                    $url = $item['image_url']['url'];
                    $images[] = [
                        'type' => 'image',
                        'source' => [
                            'type' => 'url',
                            'url' => $url,
                        ],
                    ];
                }
            }

            if (empty($images)) {
                return $text;
            }

            return [
                [
                    'type' => 'text',
                    'text' => $text,
                ],
                ...$images,
            ];
        }

        return $content;
    }

    protected function buildHeaders(array $headers): array
    {
        $result = [];
        foreach ($headers as $key => $value) {
            $result[] = "{$key}: {$value}";
        }

        return $result;
    }
}
