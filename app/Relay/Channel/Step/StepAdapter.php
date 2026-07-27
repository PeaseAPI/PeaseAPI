<?php

declare(strict_types=1);

namespace App\Relay\Channel\Step;

use App\Enums\ApiType;
use App\Relay\Channel\BaseAdapter;
use App\Relay\Channel\OpenAICompatibleTrait;
use App\Relay\Common\RelayInfo;
use App\Relay\Constant\RelayMode;

/**
 * 阶跃星辰 (StepFun) 渠道适配器
 * 对标 new-api relay/channel/step/
 * API 文档: https://platform.stepfun.com/docs
 * OpenAI 兼容
 */
class StepAdapter extends BaseAdapter
{
    use OpenAICompatibleTrait;

    protected string $name = 'step';
    protected int $apiType = ApiType::STEP->value;

    /** @var array<int, string> */
    protected array $supportedActions = [
        RelayMode::ChatCompletions,
    ];

    /**
     * StepFun 使用标准的 OpenAI 兼容 API
     * 继承 OpenAICompatibleTrait 的所有默认实现
     */
}