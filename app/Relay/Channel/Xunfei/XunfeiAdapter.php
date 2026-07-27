<?php

declare(strict_types=1);

namespace App\Relay\Channel\Xunfei;

use App\Enums\ApiType;
use App\Relay\Channel\BaseAdapter;
use App\Relay\Channel\OpenAICompatibleTrait;
use App\Relay\Constant\RelayMode;

/**
 * Xunfei 渠道适配器
 * 对标 new-api relay/channel/xunfei/
 */
class XunfeiAdapter extends BaseAdapter
{
    use OpenAICompatibleTrait;

    protected string $name = 'xunfei';
    protected int $apiType = ApiType::XUNFEI_SPARK->value;

    /** @var array<int, string> */
    protected array $supportedActions = [
        RelayMode::ChatCompletions,
    ];
}
