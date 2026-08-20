<?php

declare(strict_types=1);

namespace App\Relay\Channel\ChinaMobile;

use App\Relay\Channel\BaseAdapter;
use App\Relay\Channel\OpenAICompatibleTrait;
use App\Relay\Common\RelayInfo;

/**
 * 移动云 (China Mobile Cloud / eCloud) 适配器
 *
 * 移动云 AI 开放平台提供兼容 OpenAI 格式的 API 接口
 * 文档: https://ecloud.10086.cn/op-help-center/doc/article/98322
 *
 * 特性:
 * - 兼容 OpenAI Chat Completions API 格式
 * - 支持流式 (SSE) 和非流式响应
 * - 支持 Embeddings 接口
 * - 认证方式: API Key (Bearer Token)
 *
 * 使用方式:
 * - base_url: 移动云 API 网关地址 (如 https://api.ecloud.com/v1)
 * - key: 移动云 API Key
 * - 其他参数通过 channel.setting 配置
 */
class ChinaMobileAdapter extends BaseAdapter
{
    use OpenAICompatibleTrait;

    /**
     * 移动云默认 API 基础地址
     */
    protected function getDefaultBaseUrl(): string
    {
        return 'https://api.ecloud.com/v1';
    }

    /**
     * 请求前处理 - 移动云特殊逻辑
     * 移动云 API 兼容 OpenAI 格式，大部分情况下无需额外转换
     */
    public function formatRequest(RelayInfo $info): void
    {
        // 移动云兼容 OpenAI 格式，使用默认的 OpenAI 兼容处理
        $this->formatOpenAICompatibleRequest($info);
    }

    /**
     * 响应处理 - 移动云特殊逻辑
     */
    public function formatResponse(RelayInfo $info): void
    {
        // 移动云响应格式与 OpenAI 兼容
        $this->formatOpenAICompatibleResponse($info);
    }

    /**
     * 错误处理 - 移动云错误格式
     *
     * 移动云错误响应格式:
     * {
     *   "error": {
     *     "code": "xxx",
     *     "message": "错误描述",
     *     "requestId": "xxx"
     *   }
     * }
     */
    public function errorHandler(RelayInfo $info): void
    {
        parent::errorHandler($info);
    }
}
