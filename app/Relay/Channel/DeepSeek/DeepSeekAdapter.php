<?php

declare(strict_types=1);

namespace App\Relay\Channel\DeepSeek;

use App\Enums\ApiType;
use App\Relay\Channel\BaseAdapter;
use App\Relay\Channel\OpenAICompatibleTrait;
use App\Relay\Common\RelayInfo;
use App\Relay\Constant\RelayMode;

/**
 * DeepSeek 渠道适配器
 * 对标 new-api relay/channel/deepseek/
 * API 文档: https://platform.deepseek.com/docs/api
 */
class DeepSeekAdapter extends BaseAdapter
{
    use OpenAICompatibleTrait;

    protected string $name = 'deepseek';
    protected int $apiType = ApiType::DEEPSEEK->value;

    /** @var array<int, string> */
    protected array $supportedActions = [
        RelayMode::ChatCompletions,
        RelayMode::Embeddings,
    ];

    /**
     * DeepSeek 使用标准的 OpenAI 兼容 API
     * 继承 OpenAICompatibleTrait 的所有默认实现
     */
}