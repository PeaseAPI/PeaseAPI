<?php

declare(strict_types=1);

namespace App\Relay\Channel\Mistral;

use App\Enums\ApiType;
use App\Relay\Channel\BaseAdapter;
use App\Relay\Channel\OpenAICompatibleTrait;
use App\Relay\Constant\RelayMode;

/**
 * Mistral AI 渠道适配器
 * 对标 new-api relay/channel/mistral/
 */
class MistralAdapter extends BaseAdapter
{
    use OpenAICompatibleTrait;

    protected string $name = 'mistral';

    protected int $apiType = ApiType::MISTRAL->value;

    /** @var array<int, string> */
    protected array $supportedActions = [
        RelayMode::ChatCompletions,
    ];
}
