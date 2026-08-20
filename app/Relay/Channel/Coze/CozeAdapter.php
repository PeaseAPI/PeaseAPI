<?php

declare(strict_types=1);

namespace App\Relay\Channel\Coze;

use App\Enums\ApiType;
use App\Relay\Channel\BaseAdapter;
use App\Relay\Channel\OpenAICompatibleTrait;
use App\Relay\Constant\RelayMode;

/**
 * Coze 渠道适配器
 * 对标 new-api relay/channel/coze/
 */
class CozeAdapter extends BaseAdapter
{
    use OpenAICompatibleTrait;

    protected string $name = 'coze';

    protected int $apiType = ApiType::COZE->value;

    /** @var array<int, string> */
    protected array $supportedActions = [
        RelayMode::ChatCompletions,
    ];
}
