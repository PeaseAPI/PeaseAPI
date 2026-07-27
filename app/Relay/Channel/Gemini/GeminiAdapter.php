<?php

declare(strict_types=1);

namespace App\Relay\Channel\Gemini;

use App\Relay\Channel\BaseAdapter;
use App\Relay\Common\RelayInfo;

/**
 * Gemini 适配器
 */
class GeminiAdapter extends BaseAdapter
{
    public function formatRequest(RelayInfo $info): void
    {
        $body = $info->requestBody;
        
        $geminiBody = [
            'contents' => $this->convertMessages($body['messages'] ?? []),
            'generationConfig' => [
                'maxOutputTokens' => $body['max_tokens'] ?? 4096,
                'temperature' => $body['temperature'] ?? 0.7,
                'topP' => $body['top_p'] ?? 0.95,
            ],
        ];

        if (!empty($body['system'])) {
            $geminiBody['systemInstruction'] = ['parts' => [['text' => $body['system']]]];
        }

        $info->upstreamBody = json_encode($geminiBody);
        $model = $body['model'] ?? 'gemini-1.5-pro';
        $info->upstreamUrl = 'https://generativelanguage.googleapis.com/v1beta/models/' . $model . ':generateContent';
    }

    public function formatResponse(RelayInfo $info): void
    {
        $body = json_decode($info->responseBody, true);
        
        $openai = [
            'id' => 'chatcmpl-' . uniqid(),
            'object' => 'chat.completion',
            'created' => time(),
            'model' => $info->model,
            'choices' => [],
        ];

        if (!empty($body['candidates'])) {
            $content = $body['candidates'][0]['content']['parts'][0]['text'] ?? '';
            $openai['choices'][] = [
                'index' => 0,
                'message' => ['role' => 'assistant', 'content' => $content],
                'finish_reason' => 'stop',
            ];
        }

        $info->responseBody = json_encode($openai);
    }

    public function doRequest(RelayInfo $info): void
    {
        $channel = $info->channel;
        $apiKey = $channel->key;
        
        $url = $info->upstreamUrl . '?key=' . $apiKey;
        
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $info->upstreamBody,
            CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 120,
        ]);

        $info->responseBody = curl_exec($ch);
        $info->responseStatus = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
    }

    protected function convertMessages(array $messages): array
    {
        $contents = [];
        foreach ($messages as $msg) {
            $contents[] = [
                'role' => $msg['role'] === 'user' ? 'user' : 'model',
                'parts' => [['text' => $msg['content']]],
            ];
        }
        return $contents;
    }
}