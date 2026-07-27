<?php

declare(strict_types=1);

namespace App\Relay\Channel\Jimeng;

use App\Relay\Channel\BaseAdapter;
use App\Relay\Common\RelayInfo;
use App\Relay\Constant\RelayMode;

/**
 * 即梦 (Jimeng) 渠道适配器
 * 用于即梦图像/视频生成的 Relay 中转
 */
class JimengAdapter extends BaseAdapter
{
    protected function getBaseUri(RelayInfo $info): string
    {
        $channel = $info->getChannel();
        return rtrim($channel->base_url ?: 'https://jimeng.jianying.com', '/');
    }

    protected function getRequestUrl(RelayInfo $info): string
    {
        return $this->getBaseUri($info) . '/';
    }

    protected function formatRequest(RelayInfo $info): void
    {
        $body = $info->getRequestBody() ?? [];
        $info->setRequestBody($body);
    }

    protected function formatResponse(RelayInfo $info): void
    {
        // 即梦响应格式透传
    }

    protected function buildHeaders(RelayInfo $info): array
    {
        $channel = $info->getChannel();
        $apiKey = $channel->key;

        return [
            'Content-Type' => 'application/json',
            'Authorization' => 'Bearer ' . $apiKey,
        ];
    }

    public function supports(int $relayMode): bool
    {
        return in_array($relayMode, [
            RelayMode::IMAGES_GENERATIONS,
        ]);
    }
}