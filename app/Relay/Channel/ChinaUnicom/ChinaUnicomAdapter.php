<?php

declare(strict_types=1);

namespace App\Relay\Channel\ChinaUnicom;

use App\Relay\Channel\BaseAdapter;
use App\Relay\Channel\OpenAICompatibleTrait;
use App\Relay\Common\RelayInfo;

/**
 * 联通云 (China Unicom Cloud / CUCloud) 适配器
 *
 * 联通云 AI 能力平台提供兼容 OpenAI 格式的 API 接口
 * 文档: https://support.cucloud.cn/document/127/591/2357.html?id=2357&arcid=7015&lang=zh
 *
 * 特性:
 * - 兼容 OpenAI Chat Completions API 格式
 * - 支持流式 (SSE) 和非流式响应
 * - 支持 Embeddings 接口
 * - 认证方式: API Key (Bearer Token)
 *
 * 使用方式:
 * - base_url: 联通云 API 网关地址
 * - key: 联通云 API Key
 * - 其他参数通过 channel.setting 配置
 */
class ChinaUnicomAdapter extends BaseAdapter
{
    use OpenAICompatibleTrait;

    /**
     * 联通云默认 API 基础地址
     */
    protected function getDefaultBaseUrl(): string
    {
        return 'https://ai.cucloud.cn/v1';
    }

    /**
     * 请求前处理 - 联通云特殊逻辑
     * 联通云 API 兼容 OpenAI 格式，大部分情况下无需额外转换
     */
    public function formatRequest(RelayInfo $info): void
    {
        $this->formatOpenAICompatibleRequest($info);
    }

    /**
     * 响应处理 - 联通云特殊逻辑
     */
    public function formatResponse(RelayInfo $info): void
    {
        $this->formatOpenAICompatibleResponse($info);
    }

    /**
     * 错误处理 - 联通云错误格式
     */
    public function errorHandler(RelayInfo $info): void
    {
        parent::errorHandler($info);
    }
}