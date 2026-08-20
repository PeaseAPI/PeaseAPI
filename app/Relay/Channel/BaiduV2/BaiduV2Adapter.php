<?php

declare(strict_types=1);

namespace App\Relay\Channel\BaiduV2;

use App\Enums\ApiType;
use App\Relay\Channel\BaseAdapter;
use App\Relay\Channel\OpenAICompatibleTrait;
use App\Relay\Constant\RelayMode;

/**
 * BaiduV2 渠道适配器
 * 对标 new-api relay/channel/baiduv2/
 */
class BaiduV2Adapter extends BaseAdapter
{
    use OpenAICompatibleTrait;

    protected string $name = 'baiduv2';

    protected int $apiType = ApiType::BAIDU_V2->value;

    /** @var array<int, string> */
    protected array $supportedActions = [
        RelayMode::ChatCompletions,
    ];
}
