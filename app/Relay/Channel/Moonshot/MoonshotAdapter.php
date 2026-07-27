<?php

declare(strict_types=1);

namespace App\Relay\Channel\Moonshot;

use App\Enums\ApiType;
use App\Relay\Channel\BaseAdapter;
use App\Relay\Channel\OpenAICompatibleTrait;
use App\Relay\Constant\RelayMode;

/**
 * Moonshot (月之暗面 Kimi) 渠道适配器
 * 对标 new-api relay/channel/moonshot/
 */
class MoonshotAdapter extends BaseAdapter
{
    use OpenAICompatibleTrait;

    protected string $name = 'moonshot';
    protected int $apiType = ApiType::MOONSHOT_KIMI->value;

    /** @var array<int, string> */
    protected array $supportedActions = [
        RelayMode::ChatCompletions,
    ];
}