<?php

declare(strict_types=1);

namespace App\Relay\Channel\Codex;

use App\Enums\ApiType;
use App\Relay\Channel\BaseAdapter;
use App\Relay\Channel\OpenAICompatibleTrait;
use App\Relay\Constant\RelayMode;

/**
 * Codex 渠道适配器
 * 对标 new-api relay/channel/codex/
 */
class CodexAdapter extends BaseAdapter
{
    use OpenAICompatibleTrait;

    protected string $name = 'codex';
    protected int $apiType = ApiType::CODEX->value;

    /** @var array<int, string> */
    protected array $supportedActions = [
        RelayMode::ChatCompletions,
    ];
}
