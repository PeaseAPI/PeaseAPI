<?php

declare(strict_types=1);

namespace App\Relay\Channel\Tencent;

use App\Enums\ApiType;
use App\Relay\Channel\BaseAdapter;
use App\Relay\Channel\OpenAICompatibleTrait;
use App\Relay\Constant\RelayMode;

/**
 * Tencent 渠道适配器
 * 对标 new-api relay/channel/tencent/
 */
class TencentAdapter extends BaseAdapter
{
    use OpenAICompatibleTrait;

    protected string $name = 'tencent';

    protected int $apiType = ApiType::TENCENT_HUNYUAN->value;

    /** @var array<int, string> */
    protected array $supportedActions = [
        RelayMode::ChatCompletions,
    ];
}
