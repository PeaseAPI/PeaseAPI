<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Channel;
use App\Models\Ability;
use Illuminate\Support\Facades\Cache;

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
        $channel = Channel::create($data);
        $this->syncAbilities($channel);
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
            if (empty($model)) continue;
            
            foreach ($groups as $group) {
                $group = trim($group);
                if (empty($group)) continue;
                
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
            
            $response = \Illuminate\Support\Facades\Http::withHeaders([
                'Authorization' => 'Bearer ' . $key,
                'Content-Type' => 'application/json',
            ])->post("{$baseUrl}/v1/chat/completions", [
                'model' => $testModel,
                'messages' => [['role' => 'user', 'content' => 'hi']],
                'max_tokens' => 1,
            ]);
            
            $responseTime = (int)((microtime(true) - $startTime) * 1000);
            
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
                'response_time' => (int)((microtime(true) - $startTime) * 1000),
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
                ->flatMap(fn($m) => explode(',', (string)$m))
                ->map(fn($m) => trim($m))
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
        try {
            $baseUrl = rtrim((string)$channel->base_url, '/');
            $key = $channel->key;
            $response = \Illuminate\Support\Facades\Http::withHeaders([
                'Authorization' => 'Bearer ' . $key,
            ])->get("{$baseUrl}/v1/models");

            if (!$response->successful()) {
                return [];
            }
            $body = $response->json();
            $models = data_get($body, 'data', []);
            return collect($models)
                ->pluck('id')
                ->filter()
                ->values()
                ->toArray();
        } catch (\Throwable $e) {
            return [];
        }
    }

    /**
     * 禁用标签下所有渠道
     */
    public function disableByTag(string $tag): int
    {
        $ids = Channel::where('tag', $tag)->pluck('id')->toArray();
        if (empty($ids)) return 0;
        return $this->batchUpdateStatus($ids, 2);
    }

    /**
     * 启用标签下所有渠道
     */
    public function enableByTag(string $tag): int
    {
        $ids = Channel::where('tag', $tag)->pluck('id')->toArray();
        if (empty($ids)) return 0;
        return $this->batchUpdateStatus($ids, 1);
    }

    /**
     * 复制渠道
     */
    public function copyChannel(Channel $channel): Channel
    {
        $data = $channel->toArray();
        unset($data['id'], $data['created_at'], $data['updated_at']);
        $data['name'] = $data['name'] . '_copy';
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
        $existing = array_filter(explode("\n", (string)$channel->key));
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
        $current = array_filter(explode(',', (string)$channel->models));
        $added = array_diff($upstream, $current);
        $removed = array_diff($current, $upstream);
        if (!empty($added)) {
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
        $current = array_filter(explode(',', (string)$channel->models));
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
            $baseUrl = rtrim((string)$channel->base_url, '/');
            $key = $channel->key;
            $response = \Illuminate\Support\Facades\Http::withHeaders([
                'Authorization' => 'Bearer ' . $key,
            ])->get("{$baseUrl}/dashboard/billing/subscription");

            if (!$response->successful()) {
                return (float)$channel->balance;
            }
            $body = $response->json();
            $balance = (float)(data_get($body, 'hard_limit_usd', 0) - data_get($body, 'soft_limit_usd', 0));
            $channel->update([
                'balance' => max($balance, 0),
                'balance_updated_time' => time(),
            ]);
            return (float)$channel->fresh()->balance;
        } catch (\Throwable $e) {
            return (float)$channel->balance;
        }
    }
}
