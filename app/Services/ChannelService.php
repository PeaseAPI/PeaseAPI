<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Ability;
use App\Models\Channel;
use App\Models\ModelMeta;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

/**
 * 渠道管理服务
 */
class ChannelService
{
    /**
     * 创建渠道
     */
    public function create(array $data): Channel
    {
        // created_time 字段在数据库中为 NOT NULL 且无默认值，必须显式设置
        if (empty($data['created_time'])) {
            $data['created_time'] = time();
        }
        $channel = Channel::create($data);
        $this->syncAbilities($channel);
        $this->syncModelMetadata($channel->getSupportedModels());

        return $channel;
    }

    /**
     * 更新渠道
     */
    public function update(Channel $channel, array $data): bool
    {
        $channel->update($data);
        if (isset($data['models']) || isset($data['group'])) {
            $this->syncAbilities($channel);
        }
        if (isset($data['models'])) {
            $this->syncModelMetadata($channel->getSupportedModels());
        }

        return true;
    }

    /**
     * 删除渠道
     */
    public function delete(Channel $channel): bool
    {
        Ability::where('channel_id', $channel->id)->delete();
        $channel->delete();

        return true;
    }

    /**
     * 同步渠道能力表
     */
    public function syncAbilities(Channel $channel): void
    {
        Ability::where('channel_id', $channel->id)->delete();

        $models = is_string($channel->models) ? explode(',', $channel->models) : ($channel->models ?? []);
        $groups = is_string($channel->group) ? explode(',', $channel->group) : [$channel->group ?? 'default'];

        foreach ($models as $model) {
            $model = trim($model);
            if (empty($model)) {
                continue;
            }

            foreach ($groups as $group) {
                $group = trim($group);
                if (empty($group)) {
                    continue;
                }

                Ability::create([
                    'group' => $group,
                    'model' => $model,
                    'channel_id' => $channel->id,
                    'enabled' => $channel->status,
                    'priority' => $channel->priority,
                ]);
            }
        }

        $this->clearChannelCache();
    }

    /**
     * 测试渠道连通性
     */
    public function testChannel(Channel $channel): array
    {
        $startTime = microtime(true);

        try {
            $testModel = $channel->test_model ?? 'gpt-3.5-turbo';
            $baseUrl = $channel->base_url;
            $key = $channel->key;

            $response = Http::withHeaders([
                'Authorization' => 'Bearer '.$key,
                'Content-Type' => 'application/json',
            ])->post("{$baseUrl}/v1/chat/completions", [
                'model' => $testModel,
                'messages' => [['role' => 'user', 'content' => 'hi']],
                'max_tokens' => 1,
            ]);

            $responseTime = (int) ((microtime(true) - $startTime) * 1000);

            $channel->update([
                'test_time' => time(),
                'response_time' => $responseTime,
            ]);

            return [
                'success' => $response->successful(),
                'status' => $response->status(),
                'response_time' => $responseTime,
                'message' => $response->successful() ? 'OK' : $response->body(),
            ];
        } catch (\Exception $e) {
            return [
                'success' => false,
                'message' => $e->getMessage(),
                'response_time' => (int) ((microtime(true) - $startTime) * 1000),
            ];
        }
    }

    /**
     * 更新渠道状态
     */
    public function updateStatus(Channel $channel, int $status): bool
    {
        $channel->update(['status' => $status]);

        Ability::where('channel_id', $channel->id)
            ->update(['enabled' => $status]);

        $this->clearChannelCache();

        return true;
    }

    /**
     * 搜索渠道
     */
    public function search(string $keyword, int $perPage = 20)
    {
        return Channel::where('name', 'like', "%{$keyword}%")
            ->orWhere('tag', 'like', "%{$keyword}%")
            ->paginate($perPage);
    }

    /**
     * 获取渠道列表
     */
    public function list(int $perPage = 20)
    {
        return Channel::orderBy('id', 'desc')->paginate($perPage);
    }

    /**
     * 批量删除渠道
     */
    public function batchDelete(array $ids): int
    {
        Ability::whereIn('channel_id', $ids)->delete();
        $count = Channel::whereIn('id', $ids)->delete();
        $this->clearChannelCache();

        return $count;
    }

    /**
     * 批量更新状态
     */
    public function batchUpdateStatus(array $ids, int $status): int
    {
        $count = Channel::whereIn('id', $ids)->update(['status' => $status]);
        Ability::whereIn('channel_id', $ids)->update(['enabled' => $status]);
        $this->clearChannelCache();

        return $count;
    }

    /**
     * 删除所有禁用渠道 (status != 1 视为禁用)
     */
    public function deleteDisabled(): int
    {
        $ids = Channel::where('status', '!=', 1)->pluck('id')->toArray();
        Ability::whereIn('channel_id', $ids)->delete();
        $count = Channel::where('status', '!=', 1)->delete();
        $this->clearChannelCache();

        return $count;
    }

    /**
     * 清除渠道缓存
     */
    public function clearChannelCache(): void
    {
        Cache::forget('channel_cache');
        Cache::forget('ability_cache');
        Cache::forget('enabled_models');
    }

    /**
     * 获取渠道缓存
     */
    public function getChannelCache(): array
    {
        return Cache::remember('channel_cache', config('pease-api.channel_cache_frequency', 600), function () {
            return Channel::where('status', 1)->get()->toArray();
        });
    }

    /**
     * 获取所有启用模型
     */
    public function getEnabledModels(): array
    {
        return Cache::remember('enabled_models', 600, function () {
            return Ability::where('enabled', 1)
                ->distinct()
                ->pluck('model')
                ->toArray();
        });
    }

    /**
     * 获取所有渠道模型（去重合并，不限状态）
     */
    public function getAllModels(): array
    {
        return Cache::remember('all_models', 600, function () {
            return Channel::pluck('models')
                ->flatMap(fn ($m) => is_array($m) ? $m : explode(',', (string) $m))
                ->map(fn ($m) => trim($m))
                ->filter()
                ->unique()
                ->values()
                ->toArray();
        });
    }

    /**
     * 重建所有渠道能力表
     */
    public function fixAllAbilities(): int
    {
        Ability::truncate();
        $count = 0;
        Channel::chunk(100, function ($channels) use (&$count) {
            foreach ($channels as $channel) {
                $this->syncAbilities($channel);
                $count++;
            }
        });
        $this->clearChannelCache();

        return $count;
    }

    /**
     * 拉取上游模型列表
     */
    public function fetchUpstreamModels(Channel $channel): array
    {
        $result = $this->discoverModels(
            (string) $channel->base_url,
            (string) $channel->key,
            (int) $channel->type,
            is_array($channel->header_override) ? $channel->header_override : []
        );

        return $result['models'];
    }

    /**
     * 探测尚未保存的渠道，并把识别到的模型简介同步到 model_metas。
     */
    public function discoverModels(string $baseUrl, string $key, int $type = 1, array $headers = []): array
    {
        $baseUrl = rtrim(trim($baseUrl), '/');
        if ($baseUrl === '') {
            return ['models' => [], 'metadata' => [], 'message' => __('API address cannot be empty')];
        }

        $headers = array_merge(['Accept' => 'application/json'], $headers);
        if ($key !== '') {
            if ($type === 4) {
                $headers['x-api-key'] = $key;
                $headers['anthropic-version'] = $headers['anthropic-version'] ?? '2023-06-01';
            } elseif ($type !== 25 && $type !== 32) {
                $headers['Authorization'] = 'Bearer '.$key;
            }
        }

        $isOllama = $type === 32 || str_contains($baseUrl, '11434');
        $isGemini = in_array($type, [25, 26, 50], true) || str_contains($baseUrl, 'generativelanguage.googleapis.com');
        $endpoints = $this->modelEndpoints($baseUrl, $isOllama, $isGemini);

        foreach ($endpoints as $url) {
            try {
                $request = Http::timeout(20)->withHeaders($headers);
                if ($isGemini && $key !== '') {
                    $url .= (str_contains($url, '?') ? '&' : '?').'key='.rawurlencode($key);
                }
                $response = $request->get($url);
                if (! $response->successful()) {
                    continue;
                }

                $body = $response->json();
                $models = $this->extractModelNames($body, $isOllama, $isGemini);
                if ($models === []) {
                    continue;
                }
                $metadata = $this->syncModelMetadata($models);

                return ['models' => $models, 'metadata' => $metadata, 'message' => ''];
            } catch (\Throwable) {
                continue;
            }
        }

        return ['models' => [], 'metadata' => [], 'message' => __('Unable to fetch the model list from this API address. Please check the address, Key, and channel type.')];
    }

    private function modelEndpoints(string $baseUrl, bool $isOllama, bool $isGemini): array
    {
        if ($isOllama) {
            return [$baseUrl.'/api/tags', $baseUrl.'/models', $baseUrl.'/v1/models'];
        }
        if ($isGemini) {
            return [
                str_ends_with($baseUrl, '/v1beta') ? $baseUrl.'/models' : $baseUrl.'/v1beta/models',
                str_ends_with($baseUrl, '/v1') ? $baseUrl.'/models' : $baseUrl.'/v1/models',
            ];
        }

        return [
            str_ends_with($baseUrl, '/v1') ? $baseUrl.'/models' : $baseUrl.'/v1/models',
            $baseUrl.'/models',
        ];
    }

    private function extractModelNames(mixed $body, bool $isOllama, bool $isGemini): array
    {
        $items = $isOllama
            ? data_get($body, 'models', [])
            : data_get($body, 'data', data_get($body, 'models', []));
        if (! is_array($items)) {
            return [];
        }

        return collect($items)
            ->map(function ($item) use ($isGemini) {
                if (is_string($item)) {
                    return $item;
                }
                $name = data_get($item, 'id', data_get($item, 'name', data_get($item, 'model')));
                if ($isGemini && is_string($name)) {
                    $name = preg_replace('/^models\//', '', $name);
                }

                return $name;
            })
            ->filter(fn ($name) => is_string($name) && trim($name) !== '')
            ->map(fn ($name) => trim($name))
            ->unique()
            ->values()
            ->all();
    }

    /** @return array<string, array{description: string, tags: string}> */
    public function syncModelMetadata(array $models): array
    {
        $catalog = config('model_catalog', []);
        $metadata = [];
        foreach (array_unique(array_filter(array_map('trim', $models))) as $model) {
            $known = $catalog[$model] ?? null;
            $values = [
                'description' => $known['description'] ?? "上游已识别模型 {$model}，可用于当前渠道支持的对话或生成任务。",
                'tags' => $known['tags'] ?? '上游模型,自动识别',
            ];
            ModelMeta::updateOrCreate(['model_name' => $model], $values);
            $metadata[$model] = $values;
        }

        return $metadata;
    }

    /**
     * 禁用标签下所有渠道
     */
    public function disableByTag(string $tag): int
    {
        $ids = Channel::where('tag', $tag)->pluck('id')->toArray();
        if (empty($ids)) {
            return 0;
        }

        return $this->batchUpdateStatus($ids, 2);
    }

    /**
     * 启用标签下所有渠道
     */
    public function enableByTag(string $tag): int
    {
        $ids = Channel::where('tag', $tag)->pluck('id')->toArray();
        if (empty($ids)) {
            return 0;
        }

        return $this->batchUpdateStatus($ids, 1);
    }

    /**
     * 复制渠道
     */
    public function copyChannel(Channel $channel): Channel
    {
        $data = $channel->toArray();
        unset($data['id'], $data['created_at'], $data['updated_at']);
        $data['name'] = $data['name'].'_copy';
        $data['created_time'] = time();
        $newChannel = Channel::create($data);
        $this->syncAbilities($newChannel);

        return $newChannel;
    }

    /**
     * 多 Key 管理
     * action: list | add | remove
     */
    public function multiKeyManage(Channel $channel, string $action, array $keys = []): array
    {
        $existing = array_filter(explode("\n", (string) $channel->key));
        switch ($action) {
            case 'add':
                $existing = array_unique(array_merge($existing, $keys));
                $channel->update(['key' => implode("\n", $existing)]);
                break;
            case 'remove':
                $existing = array_values(array_diff($existing, $keys));
                $channel->update(['key' => implode("\n", $existing)]);
                break;
            case 'list':
            default:
                break;
        }

        return ['keys' => array_values($existing)];
    }

    /**
     * 应用上游更新 (占位实现，返回渠道模型与上游模型差异)
     */
    public function applyUpstreamUpdates(Channel $channel): array
    {
        $upstream = $this->fetchUpstreamModels($channel);
        $current = $channel->getSupportedModels();
        $added = array_diff($upstream, $current);
        $removed = array_diff($current, $upstream);
        if (! empty($added)) {
            $newModels = implode(',', array_unique(array_merge($current, $added)));
            $channel->update(['models' => $newModels]);
            $this->syncAbilities($channel);
        }

        return [
            'added' => array_values($added),
            'removed' => array_values($removed),
        ];
    }

    public function applyAllUpstreamUpdates(): array
    {
        $result = [];
        Channel::chunk(100, function ($channels) use (&$result) {
            foreach ($channels as $channel) {
                $result[$channel->id] = $this->applyUpstreamUpdates($channel);
            }
        });

        return $result;
    }

    public function detectUpstreamUpdates(Channel $channel): array
    {
        $upstream = $this->fetchUpstreamModels($channel);
        $current = $channel->getSupportedModels();

        return [
            'added' => array_values(array_diff($upstream, $current)),
            'removed' => array_values(array_diff($current, $upstream)),
        ];
    }

    public function detectAllUpstreamUpdates(): array
    {
        $result = [];
        Channel::chunk(100, function ($channels) use (&$result) {
            foreach ($channels as $channel) {
                $result[$channel->id] = $this->detectUpstreamUpdates($channel);
            }
        });

        return $result;
    }

    /**
     * 更新所有渠道余额
     */
    public function updateAllBalance(): array
    {
        $result = [];
        Channel::chunk(100, function ($channels) use (&$result) {
            foreach ($channels as $channel) {
                $result[$channel->id] = $this->updateBalance($channel);
            }
        });

        return $result;
    }

    /**
     * 更新单个渠道余额
     */
    public function updateBalance(Channel $channel): float
    {
        try {
            $baseUrl = rtrim((string) $channel->base_url, '/');
            $key = $channel->key;
            $response = Http::withHeaders([
                'Authorization' => 'Bearer '.$key,
            ])->get("{$baseUrl}/dashboard/billing/subscription");

            if (! $response->successful()) {
                return (float) $channel->balance;
            }
            $body = $response->json();
            $balance = (float) (data_get($body, 'hard_limit_usd', 0) - data_get($body, 'soft_limit_usd', 0));
            $channel->update([
                'balance' => max($balance, 0),
                'balance_updated_time' => time(),
            ]);

            return (float) $channel->fresh()->balance;
        } catch (\Throwable $e) {
            return (float) $channel->balance;
        }
    }
}
