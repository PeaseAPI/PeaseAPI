<?php

declare(strict_types=1);

namespace App\Relay\Channel;

use App\Relay\Common\RelayInfo;

/**
 * 渠道适配器接口
 * 对标 new-api relay/channel/interface.go
 */
interface ChannelAdapterInterface
{
    /**
     * 获取适配器名称
     */
    public function getName(): string;

    /**
     * 获取该渠道的 API 类型
     */
    public function getApiType(): int;

    /**
     * 获取渠道支持的功能列表
     *
     * @return array<int, string>
     */
    public function getSupportedActions(): array;

    /**
     * 格式化请求（构造发往上游的请求）
     */
    public function formatRequest(RelayInfo $info): void;

    /**
     * 执行上游 HTTP 请求
     */
    public function doRequest(RelayInfo $info): void;

    /**
     * 格式化响应（处理上游返回，做必要转换）
     */
    public function formatResponse(RelayInfo $info): void;

    /**
     * 执行响应输出（包括流式/非流式）
     */
    public function doResponse(RelayInfo $info): void;

    /**
     * 流式处理 (SSE)
     */
    public function streamHandler(RelayInfo $info): void;

    /**
     * 错误处理
     */
    public function errorHandler(RelayInfo $info): void;
}

/**
 * 通用渠道适配器抽象类（提供默认实现）
 * 对标 new-api relay/channel/adapter.go
 */
abstract class BaseAdapter implements ChannelAdapterInterface
{
    protected string $name = 'base';

    protected int $apiType = 0;

    /** @var array<int, string> */
    protected array $supportedActions = [];

    public function getName(): string
    {
        return $this->name;
    }

    public function getApiType(): int
    {
        return $this->apiType;
    }

    public function getSupportedActions(): array
    {
        return $this->supportedActions;
    }

    public function formatRequest(RelayInfo $info): void
    {
        // 默认实现
    }

    public function doRequest(RelayInfo $info): void
    {
        // 默认实现
    }

    public function formatResponse(RelayInfo $info): void
    {
        // 默认实现
    }

    public function doResponse(RelayInfo $info): void
    {
        // 默认实现
    }

    public function streamHandler(RelayInfo $info): void
    {
        // 默认实现
    }

    public function errorHandler(RelayInfo $info): void
    {
        // 默认实现
    }
}
