<?php

declare(strict_types=1);

namespace App\Relay\Channel\AI360;

use App\Enums\ApiType;
use App\Relay\Channel\BaseAdapter;
use App\Relay\Channel\OpenAICompatibleTrait;
use App\Relay\Constant\RelayMode;

/**
 * AI360 渠道适配器
 * 对标 new-api relay/channel/ai360/
 */
class AI360Adapter extends BaseAdapter
{
    use OpenAICompatibleTrait;

    protected string $name = 'ai360';
    protected int $apiType = ApiType::QIHOO_360->value;

    /** @var array<int, string> */
    protected array $supportedActions = [
        RelayMode::ChatCompletions,
    ];
}
