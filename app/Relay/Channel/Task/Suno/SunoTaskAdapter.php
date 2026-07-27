<?php

declare(strict_types=1);

namespace App\Relay\Channel\Task\Suno;

use App\Relay\Channel\Task\TaskAdapter;
use App\Relay\Common\RelayInfo;

/**
 * Suno 音乐生成任务适配器
 */
class SunoTaskAdapter extends TaskAdapter
{
    protected string $platform = 'suno';

    protected function buildSubmitUrl(string $baseUrl, RelayInfo $info): string
    {
        $action = $info->getParam('suno_action', 'generate');
        return $baseUrl . '/api/v1/suno/' . $action;
    }

    protected function buildFetchUrl(string $baseUrl, string $taskId, RelayInfo $info): string
    {
        return $baseUrl . '/api/v1/suno/fetch/' . $taskId;
    }

    protected function buildRequestBody(RelayInfo $info): array
    {
        return $info->getRequestBody() ?? [];
    }
}