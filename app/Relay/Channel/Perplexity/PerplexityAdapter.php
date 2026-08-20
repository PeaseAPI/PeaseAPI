<?php

declare(strict_types=1);

namespace App\Relay\Channel\Perplexity;

use App\Enums\ApiType;
use App\Relay\Channel\BaseAdapter;
use App\Relay\Channel\OpenAICompatibleTrait;
use App\Relay\Constant\RelayMode;

/**
 * Perplexity 渠道适配器
 * 对标 new-api relay/channel/perplexity/
 */
class PerplexityAdapter extends BaseAdapter
{
    use OpenAICompatibleTrait;

    protected string $name = 'perplexity';

    protected int $apiType = ApiType::PERPLEXITY->value;

    /** @var array<int, string> */
    protected array $supportedActions = [
        RelayMode::ChatCompletions,
    ];
}
