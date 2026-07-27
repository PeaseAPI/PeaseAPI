<?php

declare(strict_types=1);

namespace App\Relay\Channel\SiliconFlow;

use App\Enums\ApiType;
use App\Relay\Channel\BaseAdapter;
use App\Relay\Channel\OpenAICompatibleTrait;
use App\Relay\Constant\RelayMode;

/**
 * SiliconFlow 渠道适配器
 * 对标 new-api relay/channel/siliconflow/
 */
class SiliconFlowAdapter extends BaseAdapter
{
    use OpenAICompatibleTrait;

    protected string $name = 'siliconflow';
    protected int $apiType = ApiType::SILICONFLOW->value;

    /** @var array<int, string> */
    protected array $supportedActions = [
        RelayMode::ChatCompletions, RelayMode::Embeddings,
    ];
}
