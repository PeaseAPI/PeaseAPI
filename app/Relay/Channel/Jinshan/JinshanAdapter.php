<?php

declare(strict_types=1);

namespace App\Relay\Channel\Jinshan;

use App\Enums\ApiType;
use App\Relay\Channel\BaseAdapter;
use App\Relay\Channel\OpenAICompatibleTrait;
use App\Relay\Constant\RelayMode;

/**
 * 金山 (Jinshan) 渠道适配器
 * 对标 new-api relay/channel/jinshan/
 * OpenAI 兼容
 */
class JinshanAdapter extends BaseAdapter
{
    use OpenAICompatibleTrait;

    protected string $name = 'jinshan';

    protected int $apiType = ApiType::JINSHAN->value;

    /** @var array<int, string> */
    protected array $supportedActions = [
        RelayMode::ChatCompletions,
    ];

    /**
     * 金山使用标准的 OpenAI 兼容 API
     * 继承 OpenAICompatibleTrait 的所有默认实现
     */
}
