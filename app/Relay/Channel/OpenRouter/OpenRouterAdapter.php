<?php

declare(strict_types=1);

namespace App\Relay\Channel\OpenRouter;

use App\Enums\ApiType;
use App\Relay\Channel\BaseAdapter;
use App\Relay\Channel\OpenAICompatibleTrait;
use App\Relay\Constant\RelayMode;

/**
 * OpenRouter 渠道适配器
 * 对标 new-api relay/channel/openrouter/
 */
class OpenRouterAdapter extends BaseAdapter
{
    use OpenAICompatibleTrait;

    protected string $name = 'openrouter';
    protected int $apiType = ApiType::OPENROUTER->value;

    /** @var array<int, string> */
    protected array $supportedActions = [
        RelayMode::ChatCompletions,
    ];
}
