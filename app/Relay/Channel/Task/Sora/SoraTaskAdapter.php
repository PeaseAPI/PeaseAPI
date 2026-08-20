<?php

declare(strict_types=1);

namespace App\Relay\Channel\Task\Sora;

use App\Relay\Channel\Task\TaskAdapter;
use App\Relay\Common\RelayInfo;

/**
 * Sora 视频生成任务适配器 (OpenAI)
 */
class SoraTaskAdapter extends TaskAdapter
{
    protected string $platform = 'sora';

    protected function buildSubmitUrl(string $baseUrl, RelayInfo $info): string
    {
        return $baseUrl.'/v1/video/generations';
    }

    protected function buildFetchUrl(string $baseUrl, string $taskId, RelayInfo $info): string
    {
        return $baseUrl.'/v1/video/generations/'.$taskId;
    }

    protected function buildRequestBody(RelayInfo $info): array
    {
        return $info->getRequestBody() ?? [];
    }
}
