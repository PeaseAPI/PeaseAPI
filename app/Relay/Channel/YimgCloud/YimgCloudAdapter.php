<?php

declare(strict_types=1);

namespace App\Relay\Channel\YimgCloud;

use App\Enums\ApiType;
use App\Relay\Channel\BaseAdapter;
use App\Relay\Channel\OpenAICompatibleTrait;
use App\Relay\Common\RelayInfo;
use App\Relay\Constant\RelayMode;

/**
 * YimgCloud 渠道适配器
 * 对标 new-api relay/channel/yimg_cloud/
 * OpenAI 兼容图像生成
 */
class YimgCloudAdapter extends BaseAdapter
{
    use OpenAICompatibleTrait;

    protected string $name = 'yimg_cloud';
    protected int $apiType = ApiType::YIMG_CLOUD->value;

    /** @var array<int, string> */
    protected array $supportedActions = [
        RelayMode::ImageGenerations,
    ];

    /**
     * YimgCloud 使用标准的 OpenAI 兼容 API
     * 继承 OpenAICompatibleTrait 的所有默认实现
     */
}