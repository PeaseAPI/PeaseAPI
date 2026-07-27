<?php

declare(strict_types=1);

namespace App\Relay\Channel\Baidu;

use App\Enums\ApiType;
use App\Relay\Channel\BaseAdapter;
use App\Relay\Channel\OpenAICompatibleTrait;
use App\Relay\Constant\RelayMode;

/**
 * Baidu 渠道适配器
 * 对标 new-api relay/channel/baidu/
 */
class BaiduAdapter extends BaseAdapter
{
    use OpenAICompatibleTrait;

    protected string $name = 'baidu';
    protected int $apiType = ApiType::BAIDU->value;

    /** @var array<int, string> */
    protected array $supportedActions = [
        RelayMode::ChatCompletions,
    ];
}
