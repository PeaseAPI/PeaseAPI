<?php

declare(strict_types=1);

namespace App\Relay\Channel\Task\Gemini;

use App\Relay\Channel\Task\TaskAdapter;
use App\Relay\Common\RelayInfo;

/**
 * Gemini 图像/视频生成任务适配器
 */
class GeminiTaskAdapter extends TaskAdapter
{
    protected string $platform = 'gemini';

    protected function buildSubmitUrl(string $baseUrl, RelayInfo $info): string
    {
        return $baseUrl . '/v1beta/models/imagen-3-generate:predict';
    }

    protected function buildFetchUrl(string $baseUrl, string $taskId, RelayInfo $info): string
    {
        return $baseUrl . '/v1beta/models/imagen-3-generate:predict/' . $taskId;
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
            'x-goog-api-key' => $apiKey,
        ];
    }
}