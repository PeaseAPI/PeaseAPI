<?php

declare(strict_types=1);

namespace App\Relay\Channel\Task\Doubao;

use App\Relay\Channel\Task\TaskAdapter;
use App\Relay\Common\RelayInfo;

/**
 * Doubao (豆包) 视频生成任务适配器
 */
class DoubaoTaskAdapter extends TaskAdapter
{
    protected string $platform = 'doubao';

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
