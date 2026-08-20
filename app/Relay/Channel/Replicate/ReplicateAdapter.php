<?php

declare(strict_types=1);

namespace App\Relay\Channel\Replicate;

use App\Enums\ApiType;
use App\Relay\Channel\BaseAdapter;
use App\Relay\Channel\OpenAICompatibleTrait;
use App\Relay\Constant\RelayMode;

/**
 * Replicate 渠道适配器
 * 对标 new-api relay/channel/replicate/
 */
class ReplicateAdapter extends BaseAdapter
{
    use OpenAICompatibleTrait;

    protected string $name = 'replicate';

    protected int $apiType = ApiType::REPLICATE->value;

    /** @var array<int, string> */
    protected array $supportedActions = [
        RelayMode::ChatCompletions,
    ];
}
