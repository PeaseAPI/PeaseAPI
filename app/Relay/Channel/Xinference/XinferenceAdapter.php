<?php

declare(strict_types=1);

namespace App\Relay\Channel\Xinference;

use App\Enums\ApiType;
use App\Relay\Channel\BaseAdapter;
use App\Relay\Channel\OpenAICompatibleTrait;
use App\Relay\Constant\RelayMode;

/**
 * Xinference 渠道适配器
 * 对标 new-api relay/channel/xinference/
 */
class XinferenceAdapter extends BaseAdapter
{
    use OpenAICompatibleTrait;

    protected string $name = 'xinference';

    protected int $apiType = ApiType::XINFERENCE->value;

    /** @var array<int, string> */
    protected array $supportedActions = [
        RelayMode::ChatCompletions, RelayMode::Embeddings,
    ];
}
