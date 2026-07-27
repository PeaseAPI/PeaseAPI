<?php

declare(strict_types=1);

namespace App\Relay\Channel\Cohere;

use App\Enums\ApiType;
use App\Relay\Channel\BaseAdapter;
use App\Relay\Channel\OpenAICompatibleTrait;
use App\Relay\Constant\RelayMode;

/**
 * Cohere 渠道适配器
 * 对标 new-api relay/channel/cohere/
 */
class CohereAdapter extends BaseAdapter
{
    use OpenAICompatibleTrait;

    protected string $name = 'cohere';
    protected int $apiType = ApiType::COHERE->value;

    /** @var array<int, string> */
    protected array $supportedActions = [
        RelayMode::ChatCompletions,
        RelayMode::Embeddings,
        RelayMode::Rerank,
    ];

    protected function getDefaultPathMap(): array
    {
        return [
            RelayMode::ChatCompletions => '/v1/chat',
            RelayMode::Embeddings => '/v1/embeddings',
            RelayMode::Rerank => '/v1/rerank',
        ];
    }

    protected function buildRequestHeaders(RelayInfo $info): array
    {
        $headers = [
            'Authorization' => 'Bearer ' . $info->apiKey,
            'Content-Type' => 'application/json',
            'Accept' => 'application/json',
        ];

        foreach ($info->headersOverride as $key => $value) {
            $headers[$key] = (string) $value;
        }

        return $headers;
    }
}