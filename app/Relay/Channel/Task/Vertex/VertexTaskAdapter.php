<?php

declare(strict_types=1);

namespace App\Relay\Channel\Task\Vertex;

use App\Relay\Channel\Task\TaskAdapter;
use App\Relay\Common\RelayInfo;

/**
 * Vertex AI 视频生成任务适配器
 */
class VertexTaskAdapter extends TaskAdapter
{
    protected string $platform = 'vertex';

    protected function buildSubmitUrl(string $baseUrl, RelayInfo $info): string
    {
        return $baseUrl . '/v1beta/video/generations';
    }

    protected function buildFetchUrl(string $baseUrl, string $taskId, RelayInfo $info): string
    {
        return $baseUrl . '/v1beta/video/generations/' . $taskId;
    }

    protected function buildRequestBody(RelayInfo $info): array
    {
        return $info->getRequestBody() ?? [];
    }

    protected function buildHeaders(RelayInfo $info): array
    {
        $channel = $info->getChannel();
        $apiKey = $this->getApiKey($channel);

        return [
            'Content-Type' => 'application/json',
            'Authorization' => 'Bearer ' . $apiKey,
        ];
    }
}