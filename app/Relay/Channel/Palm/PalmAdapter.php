<?php

declare(strict_types=1);

namespace App\Relay\Channel\Palm;

use App\Enums\ApiType;
use App\Relay\Channel\BaseAdapter;
use App\Relay\Channel\OpenAICompatibleTrait;
use App\Relay\Constant\RelayMode;

/**
 * Palm 渠道适配器
 * 对标 new-api relay/channel/palm/
 */
class PalmAdapter extends BaseAdapter
{
    use OpenAICompatibleTrait;

    protected string $name = 'palm';

    protected int $apiType = ApiType::PALM->value;

    /** @var array<int, string> */
    protected array $supportedActions = [
        RelayMode::ChatCompletions,
    ];
}
