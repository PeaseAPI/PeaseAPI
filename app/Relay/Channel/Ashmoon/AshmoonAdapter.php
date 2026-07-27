<?php

declare(strict_types=1);

namespace App\Relay\Channel\Ashmoon;

use App\Enums\ApiType;
use App\Relay\Channel\BaseAdapter;
use App\Relay\Channel\OpenAICompatibleTrait;
use App\Relay\Common\RelayInfo;
use App\Relay\Constant\RelayMode;

/**
 * Ashmoon 渠道适配器
 * 对标 new-api relay/channel/ashmoon/
 * OpenAI 兼容
 */
class AshmoonAdapter extends BaseAdapter
{
    use OpenAICompatibleTrait;

    protected string $name = 'ashmoon';
    protected int $apiType = ApiType::ASHMOON->value;

    /** @var array<int, string> */
    protected array $supportedActions = [
        RelayMode::ChatCompletions,
    ];

    /**
     * Ashmoon 使用标准的 OpenAI 兼容 API
     * 继承 OpenAICompatibleTrait 的所有默认实现
     */
}