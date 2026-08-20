<?php

declare(strict_types=1);

namespace App\Relay\Channel;

use App\Relay\Common\RelayInfo;

/**
 * 基础适配器 - 提供通用方法
 */
abstract class BaseAdapter implements ChannelAdapterInterface
{
    public function formatRequest(RelayInfo $info): void
    {
        // 默认实现
    }

    public function formatResponse(RelayInfo $info): void
    {
        // 默认实现
    }

    public function doRequest(RelayInfo $info): void
    {
        // 默认实现
    }

    public function doResponse(RelayInfo $info): void
    {
        // 默认实现
    }

    public function streamHandler(RelayInfo $info, callable $callback): void
    {
        // 默认实现
    }

    public function errorHandler(RelayInfo $info): void
    {
        // 默认实现
    }

    protected function buildHeaders(array $headers): array
    {
        $result = [];
        foreach ($headers as $key => $value) {
            $result[] = "{$key}: {$value}";
        }

        return $result;
    }
}
