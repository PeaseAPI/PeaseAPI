<?php

declare(strict_types=1);

namespace App\Relay\Channel\AWS;

use App\Relay\Channel\BaseAdapter;
use App\Relay\Common\RelayInfo;

/**
 * AWS Bedrock 适配器
 */
class AWSAdapter extends BaseAdapter
{
    public function formatRequest(RelayInfo $info): void
    {
        $body = $info->requestBody;

        $awsBody = [
            'modelId' => $body['model'] ?? 'anthropic.claude-3-sonnet-20240229-v1:0',
            'messages' => $body['messages'] ?? [],
            'inferenceConfig' => [
                'maxTokens' => $body['max_tokens'] ?? 4096,
                'temperature' => $body['temperature'] ?? 0.7,
                'topP' => $body['top_p'] ?? 0.95,
            ],
        ];

        $info->upstreamBody = json_encode($awsBody);
    }

    public function formatResponse(RelayInfo $info): void
    {
        $body = json_decode($info->responseBody, true);

        $openai = [
            'id' => 'chatcmpl-'.uniqid(),
            'object' => 'chat.completion',
            'created' => time(),
            'model' => $info->model,
            'choices' => [],
        ];

        if (! empty($body['output']['message']['content'])) {
            $content = $body['output']['message']['content'][0]['text'] ?? '';
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

        $ch = curl_init($info->upstreamUrl);
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $info->upstreamBody,
            CURLOPT_HTTPHEADER => [
                'Content-Type: application/json',
                'Authorization: Bearer '.$channel->key,
            ],
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 120,
        ]);

        $info->responseBody = curl_exec($ch);
        $info->responseStatus = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
    }
}
