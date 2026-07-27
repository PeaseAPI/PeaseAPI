<?php

declare(strict_types=1);

namespace App\Relay\Channel\Sanlian;

use App\Enums\ApiType;
use App\Relay\Channel\BaseAdapter;
use App\Relay\Channel\OpenAICompatibleTrait;
use App\Relay\Common\RelayInfo;
use App\Relay\Constant\RelayMode;

/**
 * Sanlian 渠道适配器
 * 对标 new-api relay/channel/sanlian/
 * OpenAI 兼容
 */
class SanlianAdapter extends BaseAdapter
{
    use OpenAICompatibleTrait;

    protected string $name = 'sanlian';
    protected int $apiType = ApiType::SANLIAN->value;

    /** @var array<int, string> */
    protected array $supportedActions = [
        RelayMode::ChatCompletions,
    ];

    /**
     * Sanlian 使用标准的 OpenAI 兼容 API
     * 继承 OpenAICompatibleTrait 的所有默认实现
     */
}