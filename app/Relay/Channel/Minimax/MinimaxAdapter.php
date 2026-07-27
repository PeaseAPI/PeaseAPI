<?php

declare(strict_types=1);

namespace App\Relay\Channel\Minimax;

use App\Enums\ApiType;
use App\Relay\Channel\BaseAdapter;
use App\Relay\Channel\OpenAICompatibleTrait;
use App\Relay\Constant\RelayMode;

/**
 * Minimax 渠道适配器
 * 对标 new-api relay/channel/minimax/
 */
class MinimaxAdapter extends BaseAdapter
{
    use OpenAICompatibleTrait;

    protected string $name = 'minimax';
    protected int $apiType = ApiType::MINIMAX->value;

    /** @var array<int, string> */
    protected array $supportedActions = [
        RelayMode::ChatCompletions,
    ];
}
