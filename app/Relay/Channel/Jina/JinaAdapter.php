<?php

declare(strict_types=1);

namespace App\Relay\Channel\Jina;

use App\Enums\ApiType;
use App\Relay\Channel\BaseAdapter;
use App\Relay\Channel\OpenAICompatibleTrait;
use App\Relay\Constant\RelayMode;

/**
 * Jina 渠道适配器
 * 对标 new-api relay/channel/jina/
 */
class JinaAdapter extends BaseAdapter
{
    use OpenAICompatibleTrait;

    protected string $name = 'jina';

    protected int $apiType = ApiType::JINA->value;

    /** @var array<int, string> */
    protected array $supportedActions = [
        RelayMode::Rerank,
    ];
}
