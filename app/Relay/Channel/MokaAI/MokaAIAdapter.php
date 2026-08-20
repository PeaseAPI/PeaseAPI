<?php

declare(strict_types=1);

namespace App\Relay\Channel\MokaAI;

use App\Enums\ApiType;
use App\Relay\Channel\BaseAdapter;
use App\Relay\Channel\OpenAICompatibleTrait;
use App\Relay\Constant\RelayMode;

/**
 * MokaAI 渠道适配器
 * 对标 new-api relay/channel/mokaai/
 */
class MokaAIAdapter extends BaseAdapter
{
    use OpenAICompatibleTrait;

    protected string $name = 'mokaai';

    protected int $apiType = ApiType::MOKA_ML->value;

    /** @var array<int, string> */
    protected array $supportedActions = [
        RelayMode::ChatCompletions,
    ];
}
