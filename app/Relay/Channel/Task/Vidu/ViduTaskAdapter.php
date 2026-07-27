<?php

declare(strict_types=1);

namespace App\Relay\Channel\Task\Vidu;

use App\Relay\Channel\Task\TaskAdapter;
use App\Relay\Common\RelayInfo;

/**
 * Vidu 视频生成任务适配器
 */
class ViduTaskAdapter extends TaskAdapter
{
    protected string $platform = 'vidu';

    protected function buildSubmitUrl(string $baseUrl, RelayInfo $info): string
    {
        return $baseUrl . '/v1/video/generations';
    }

    protected function buildFetchUrl(string $baseUrl, string $taskId, RelayInfo $info): string
    {
        return $baseUrl . '/v1/video/generations/' . $taskId;
    }

    protected function buildRequestBody(RelayInfo $info): array
    {
        return $info->getRequestBody() ?? [];
    }
}