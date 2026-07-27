<?php

declare(strict_types=1);

namespace App\Relay\Channel\Ollama;

use App\Enums\ApiType;
use App\Relay\Channel\BaseAdapter;
use App\Relay\Channel\OpenAICompatibleTrait;
use App\Relay\Constant\RelayMode;

/**
 * Ollama 渠道适配器
 * 对标 new-api relay/channel/ollama/
 */
class OllamaAdapter extends BaseAdapter
{
    use OpenAICompatibleTrait;

    protected string $name = 'ollama';
    protected int $apiType = ApiType::OLLAMA->value;

    /** @var array<int, string> */
    protected array $supportedActions = [
        RelayMode::ChatCompletions, RelayMode::Embeddings,
    ];
}
