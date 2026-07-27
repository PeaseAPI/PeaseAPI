<?php

declare(strict_types=1);

namespace App\Relay\Channel\ZhipuV4;

use App\Enums\ApiType;
use App\Relay\Channel\BaseAdapter;
use App\Relay\Channel\OpenAICompatibleTrait;
use App\Relay\Constant\RelayMode;

/**
 * ZhipuV4 渠道适配器
 * 对标 new-api relay/channel/zhipuv4/
 */
class ZhipuV4Adapter extends BaseAdapter
{
    use OpenAICompatibleTrait;

    protected string $name = 'zhipuv4';
    protected int $apiType = ApiType::ZHIPU->value;

    /** @var array<int, string> */
    protected array $supportedActions = [
        RelayMode::ChatCompletions,
    ];
}
