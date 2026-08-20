<?php

declare(strict_types=1);

namespace App\Relay\Channel\Cloudflare;

use App\Enums\ApiType;
use App\Relay\Channel\BaseAdapter;
use App\Relay\Channel\OpenAICompatibleTrait;
use App\Relay\Constant\RelayMode;

/**
 * Cloudflare 渠道适配器
 * 对标 new-api relay/channel/cloudflare/
 */
class CloudflareAdapter extends BaseAdapter
{
    use OpenAICompatibleTrait;

    protected string $name = 'cloudflare';

    protected int $apiType = ApiType::CLOUDFLARE_WORKERS->value;

    /** @var array<int, string> */
    protected array $supportedActions = [
        RelayMode::ChatCompletions,
    ];
}
