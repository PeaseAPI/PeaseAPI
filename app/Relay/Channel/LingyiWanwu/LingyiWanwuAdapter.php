<?php

declare(strict_types=1);

namespace App\Relay\Channel\LingyiWanwu;

use App\Enums\ApiType;
use App\Relay\Channel\BaseAdapter;
use App\Relay\Channel\OpenAICompatibleTrait;
use App\Relay\Constant\RelayMode;

/**
 * LingyiWanwu 渠道适配器
 * 对标 new-api relay/channel/lingyiwanwu/
 */
class LingyiWanwuAdapter extends BaseAdapter
{
    use OpenAICompatibleTrait;

    protected string $name = 'lingyiwanwu';
    protected int $apiType = ApiType::LINGYIWANWU->value;

    /** @var array<int, string> */
    protected array $supportedActions = [
        RelayMode::ChatCompletions,
    ];
}
