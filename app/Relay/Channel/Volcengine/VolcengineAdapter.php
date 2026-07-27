<?php

declare(strict_types=1);

namespace App\Relay\Channel\Volcengine;

use App\Relay\Channel\BaseAdapter;
use App\Relay\Channel\OpenAICompatibleTrait;
use App\Relay\Common\RelayInfo;

/**
 * 火山引擎 (Volcengine / ByteDance) 适配器
 *
 * 火山引擎 AI 开放平台提供兼容 OpenAI 格式的 API 接口
 * 文档: https://docs.volcengine.com/docs/82379/1925114
 *
 * 支持的模型:
 * -Doubao: 豆包模型系列 (推荐)
 * -SkyLint: 语义理解模型
 * -CharacterGLM: 角色扮演模型
 * -Copedia: 问答知识库模型
 * -SkyReel: 视频生成模型
 *
 * 特性:
 * - 兼容 OpenAI Chat Completions API 格式
 * - 支持流式 (SSE) 和非流式响应
 * - 支持 Embeddings 接口
 * - 支持 Function Calling
 * - 认证方式: API Key (Bearer Token)
 */
class VolcengineAdapter extends BaseAdapter
{
    use OpenAICompatibleTrait;

    /**
     * 火山引擎默认 API 基础地址
     * 火山引擎开放平台 OpenAI 兼容 API
     */
    protected function getDefaultBaseUrl(): string
    {
        return 'https://ark.cn-beijing.volces.com/api/v3';
    }

    /**
     * 请求前处理 - 火山引擎特殊逻辑
     * 火山引擎 API 兼容 OpenAI 格式，但需要特殊的模型名称前缀
     */
    public function formatRequest(RelayInfo $info): void
    {
        // 火山引擎需要添加 "ark-" 前缀到模型名称
        // 例如: doubao-lite -> ark-doubao-lite
        if (isset($info->request['model'])) {
            $model = $info->request['model'];
            if (!str_starts_with($model, 'ark-')) {
                $info->request['model'] = 'ark-' . $model;
            }
        }

        $this->formatOpenAICompatibleRequest($info);
    }

    /**
     * 响应处理 - 火山引擎特殊逻辑
     * 火山引擎响应格式与 OpenAI 兼容，但需要移除 "ark-" 前缀
     */
    public function formatResponse(RelayInfo $info): void
    {
        $this->formatOpenAICompatibleResponse($info);

        // 如果需要，可以在这里移除模型名称中的 "ark-" 前缀
        // 但通常保留以便追踪来源
    }

    /**
     * 错误处理 - 火山引擎错误格式
     *
     * 火山引擎错误响应格式:
     * {
     *   "error": {
     *     "code": "invalid_request_error",
     *     "message": "错误描述",
     *     "param": null,
     *     "type": "invalid_request_error"
     *   }
     * }
     */
    public function errorHandler(RelayInfo $info): void
    {
        parent::errorHandler($info);
    }
}