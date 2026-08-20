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

        // Token usage
        if (isset($body['usage'])) {
            $openai['usage'] = [
                'prompt_tokens' => $body['usage']['input_tokens'] ?? 0,
                'completion_tokens' => $body['usage']['output_tokens'] ?? 0,
                'total_tokens' => ($body['usage']['input_tokens'] ?? 0) + ($body['usage']['output_tokens'] ?? 0),
            ];
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

    public function streamHandler(RelayInfo $info, callable $callback): void
    {
        $channel = $info->channel;

        $headers = [
            'Content-Type' => 'application/json',
            'x-api-key' => $channel->key,
            'anthropic-version' => '2023-06-01',
        ];

        $ch = curl_init($info->upstreamUrl);
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $info->upstreamBody,
            CURLOPT_HTTPHEADER => $this->buildHeaders($headers),
            CURLOPT_RETURNTRANSFER => false,
            CURLOPT_TIMEOUT => 120,
            CURLOPT_WRITEFUNCTION => function ($ch, $data) use ($callback) {
                $lines = explode("\n", $data);
                foreach ($lines as $line) {
                    if (str_starts_with($line, 'data: ')) {
                        $json = substr($line, 6);
                        $event = json_decode($json, true);

                        if ($event['type'] === 'content_block_delta') {
                            $text = $event['delta']['text'] ?? '';
                            $callback('data: '.json_encode([
                                'choices' => [[
                                    'index' => 0,
                                    'delta' => [
                                        'content' => $text,
                                    ],
                                ]],
                            ])."\n\n");
                        } elseif ($event['type'] === 'message_delta') {
                            $callback('data: '.json_encode([
                                'choices' => [[
                                    'index' => 0,
                                    'delta' => [],
                                    'finish_reason' => $event['delta']['stop_reason'] ?? 'stop',
                                ]],
                                'usage' => [
                                    'completion_tokens' => $event['usage']['output_tokens'] ?? 0,
                                ],
                            ])."\n\n");
                        }
                    }
                }

                return strlen($data);
            },
        ]);

        curl_exec($ch);
        curl_close($ch);

        $callback("data: [DONE]\n\n");
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
