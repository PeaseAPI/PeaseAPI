<?php

declare(strict_types=1);

namespace App\Relay\Channel\Dify;

use App\Enums\ApiType;
use App\Relay\Channel\BaseAdapter;
use App\Relay\Channel\OpenAICompatibleTrait;
use App\Relay\Constant\RelayMode;

/**
 * Dify 渠道适配器
 * 对标 new-api relay/channel/dify/
 */
class DifyAdapter extends BaseAdapter
{
    use OpenAICompatibleTrait;

    protected string $name = 'dify';
    protected int $apiType = ApiType::DIFY->value;

    /** @var array<int, string> */
    protected array $supportedActions = [
        RelayMode::ChatCompletions,
    ];
}
