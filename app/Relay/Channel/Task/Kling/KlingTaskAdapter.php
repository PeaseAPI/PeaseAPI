<?php

declare(strict_types=1);

namespace App\Relay\Channel\Task\Kling;

use App\Relay\Channel\Task\TaskAdapter;
use App\Relay\Common\RelayInfo;

/**
 * Kling 视频生成任务适配器
 */
class KlingTaskAdapter extends TaskAdapter
{
    protected string $platform = 'kling';

    protected function buildSubmitUrl(string $baseUrl, RelayInfo $info): string
    {
        $action = $info->getParam('kling_action', 'text2video');
        return $baseUrl . '/v1/videos/' . $action;
    }

    protected function buildFetchUrl(string $baseUrl, string $taskId, RelayInfo $info): string
    {
        $action = $info->getParam('kling_action', 'text2video');
        return $baseUrl . '/v1/videos/' . $action . '/' . $taskId;
    }

    protected function buildRequestBody(RelayInfo $info): array
    {
        return $info->getRequestBody() ?? [];
    }

    protected function buildHeaders(RelayInfo $info): array
    {
        $channel = $info->getChannel();
        $apiKey = $this->getApiKey($channel);
        $parts = explode(':', $apiKey);
        $accessKey = $parts[0] ?? '';
        $secretKey = $parts[1] ?? '';

        return [
            'Content-Type' => 'application/json',
            'X-API-Key' => $accessKey,
        ];
    }
}