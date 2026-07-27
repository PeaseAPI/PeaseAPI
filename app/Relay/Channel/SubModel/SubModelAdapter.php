<?php

declare(strict_types=1);

namespace App\Relay\Channel\SubModel;

use App\Enums\ApiType;
use App\Relay\Channel\BaseAdapter;
use App\Relay\Channel\OpenAICompatibleTrait;
use App\Relay\Constant\RelayMode;

/**
 * SubModel 渠道适配器
 * 对标 new-api relay/channel/submodel/
 */
class SubModelAdapter extends BaseAdapter
{
    use OpenAICompatibleTrait;

    protected string $name = 'submodel';
    protected int $apiType = ApiType::SUBMODEL->value;

    /** @var array<int, string> */
    protected array $supportedActions = [
        RelayMode::ChatCompletions,
    ];
}
