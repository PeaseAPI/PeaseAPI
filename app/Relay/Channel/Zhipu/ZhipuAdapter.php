<?php

declare(strict_types=1);

namespace App\Relay\Channel\Zhipu;

use App\Enums\ApiType;
use App\Relay\Channel\BaseAdapter;
use App\Relay\Channel\OpenAICompatibleTrait;
use App\Relay\Constant\RelayMode;

/**
 * Zhipu 渠道适配器
 * 对标 new-api relay/channel/zhipu/
 */
class ZhipuAdapter extends BaseAdapter
{
    use OpenAICompatibleTrait;

    protected string $name = 'zhipu';

    protected int $apiType = ApiType::ZHIPU_BIGMODEL->value;

    /** @var array<int, string> */
    protected array $supportedActions = [
        RelayMode::ChatCompletions, RelayMode::ImageGenerations,
    ];
}
