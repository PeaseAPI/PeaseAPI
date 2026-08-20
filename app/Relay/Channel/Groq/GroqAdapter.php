<?php

declare(strict_types=1);

namespace App\Relay\Channel\Groq;

use App\Enums\ApiType;
use App\Relay\Channel\BaseAdapter;
use App\Relay\Channel\OpenAICompatibleTrait;
use App\Relay\Constant\RelayMode;

/**
 * Groq 渠道适配器
 * 对标 new-api relay/channel/groq/
 * API 文档: https://console.groq.com/docs/api
 * 超低延迟推理，OpenAI 兼容
 */
class GroqAdapter extends BaseAdapter
{
    use OpenAICompatibleTrait;

    protected string $name = 'groq';

    protected int $apiType = ApiType::GROQ->value;

    /** @var array<int, string> */
    protected array $supportedActions = [
        RelayMode::ChatCompletions,
    ];

    /**
     * Groq 使用标准的 OpenAI 兼容 API
     * 继承 OpenAICompatibleTrait 的所有默认实现
     */
}
