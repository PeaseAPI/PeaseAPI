<?php

declare(strict_types=1);

namespace App\Relay\Channel\AdvancedCustom;

use App\Enums\ApiType;
use App\Relay\Channel\BaseAdapter;
use App\Relay\Channel\OpenAICompatibleTrait;
use App\Relay\Constant\RelayMode;

/**
 * AdvancedCustom 渠道适配器
 * 对标 new-api relay/channel/advancedcustom/
 */
class AdvancedCustomAdapter extends BaseAdapter
{
    use OpenAICompatibleTrait;

    protected string $name = 'advancedcustom';

    protected int $apiType = ApiType::CUSTOM->value;

    /** @var array<int, string> */
    protected array $supportedActions = [
        RelayMode::ChatCompletions,
    ];
}
