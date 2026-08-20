<?php

declare(strict_types=1);

namespace App\Relay\Channel\Task\Jimeng;

use App\Relay\Channel\Task\TaskAdapter;
use App\Relay\Common\RelayInfo;

/**
 * Jimeng (即梦) 图像/视频生成任务适配器
 */
class JimengTaskAdapter extends TaskAdapter
{
    protected string $platform = 'jimeng';

    protected function buildSubmitUrl(string $baseUrl, RelayInfo $info): string
    {
        $type = $info->getParam('jimeng_type', 'image');
        if ($type === 'video') {
            return $baseUrl.'/api/v1/jimeng/video/generations';
        }

        return $baseUrl.'/api/v1/jimeng/image/generations';
    }

    protected function buildFetchUrl(string $baseUrl, string $taskId, RelayInfo $info): string
    {
        $type = $info->getParam('jimeng_type', 'image');
        if ($type === 'video') {
            return $baseUrl.'/api/v1/jimeng/video/generations/'.$taskId;
        }

        return $baseUrl.'/api/v1/jimeng/image/generations/'.$taskId;
    }

    protected function buildRequestBody(RelayInfo $info): array
    {
        return $info->getRequestBody() ?? [];
    }
}
