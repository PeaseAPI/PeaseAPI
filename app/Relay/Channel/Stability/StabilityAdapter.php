<?php

declare(strict_types=1);

namespace App\Relay\Channel\Stability;

use App\Enums\ApiType;
use App\Relay\Channel\BaseAdapter;
use App\Relay\Channel\OpenAICompatibleTrait;
use App\Relay\Constant\RelayMode;

/**
 * Stability AI 渠道适配器
 * 对标 new-api relay/channel/stability/
 * API 文档: https://platform.stability.ai/docs/api-reference
 * 图像生成
 */
class StabilityAdapter extends BaseAdapter
{
    use OpenAICompatibleTrait;

    protected string $name = 'stability';

    protected int $apiType = ApiType::STABILITY->value;

    /** @var array<int, string> */
    protected array $supportedActions = [
        RelayMode::ImageGenerations,
    ];

    /**
     * Stability AI 通过 OpenAI 兼容的图像生成接口调用
     * 继承 OpenAICompatibleTrait 的所有默认实现
     */
}
