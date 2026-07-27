<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Channel;
use App\Models\Ability;
use App\Models\User;
use Illuminate\Support\Facades\Cache;

/**
 * 渠道选择算法 - 负载均衡、优先级、亲和性
 */
class ChannelSelectService
{
    /**
     * 为模型选择最佳渠道
     */
    public function selectChannel(string $model, string $group = 'default', ?User $user = null): ?Channel
    {
        $channels = $this->getAvailableChannels($model, $group);
        
        if (empty($channels)) {
            return null;
        }
        
        $channels = $this->sortByWeight($channels);
        
        if ($user) {
            $channels = $this->applyAffinity($channels, $user, $model);
        }
        
        $channels = $this->filterByHealth($channels);
        
        return $channels[0] ?? null;
    }

    /**
     * 获取可用渠道列表
     */
    public function getAvailableChannels(string $model, string $group = 'default'): array
    {
        $abilities = Ability::where('model', $model)
            ->where('group', $group)
            ->where('enabled', true)
            ->orderBy('priority', 'desc')
            ->get();
        
        if ($abilities->isEmpty()) {
            $abilities = Ability::where('model', $model)
                ->where('group', 'default')
                ->where('enabled', true)
                ->orderBy('priority', 'desc')
                ->get();
        }
        
        $channelIds = $abilities->pluck('channel_id')->toArray();
        
        return Channel::whereIn('id', $channelIds)
            ->where('status', 1)
            ->get()
            ->toArray();
    }

    /**
     * 按权重排序渠道
     */
    public function sortByWeight(array $channels): array
    {
        usort($channels, function ($a, $b) {
            return ($b['weight'] ?? 0) <=> ($a['weight'] ?? 0);
        });
        
        return $channels;
    }

    /**
     * 应用渠道亲和性
     */
    public function applyAffinity(array $channels, User $user, string $model): array
    {
        $affinityKey = "channel_affinity:{$user->id}:{$model}";
        $preferredChannelId = Cache::get($affinityKey);
        
        if (!$preferredChannelId) {
            return $channels;
        }
        
        usort($channels, function ($a, $b) use ($preferredChannelId) {
            if ($a['id'] == $preferredChannelId) return -1;
            if ($b['id'] == $preferredChannelId) return 1;
            return 0;
        });
        
        return $channels;
    }

    /**
     * 记录渠道亲和性
     */
    public function recordAffinity(int $userId, int $channelId, string $model): void
    {
        $affinityKey = "channel_affinity:{$userId}:{$model}";
        Cache::put($affinityKey, $channelId, 86400 * 30);
    }

    /**
     * 按健康状态过滤渠道
     */
    public function filterByHealth(array $channels): array
    {
        return array_filter($channels, function ($channel) {
            if (($channel['response_time'] ?? 0) > 30000) {
                return false;
            }
            
            if (($channel['auto_ban'] ?? 1) === 0 && ($channel['status'] ?? 1) !== 1) {
                return false;
            }
            
            return true;
        });
    }

    /**
     * 选择多个渠道（用于重试）
     */
    public function selectMultipleChannels(string $model, string $group = 'default', int $count = 3): array
    {
        $channels = $this->getAvailableChannels($model, $group);
        $channels = $this->sortByWeight($channels);
        $channels = $this->filterByHealth($channels);
        
        return array_slice($channels, 0, $count);
    }

    /**
     * 检查渠道是否支持模型
     */
    public function isChannelSupportsModel(int $channelId, string $model): bool
    {
        return Ability::where('channel_id', $channelId)
            ->where('model', $model)
            ->where('enabled', true)
            ->exists();
    }

    /**
     * 获取分组下所有启用的模型
     */
    public function getEnabledModels(string $group = 'default'): array
    {
        return Ability::where('group', $group)
            ->where('enabled', true)
            ->distinct()
            ->pluck('model')
            ->toArray();
    }

    /**
     * 获取渠道负载信息
     */
    public function getChannelLoad(int $channelId): array
    {
        $channel = Channel::find($channelId);
        
        if (!$channel) {
            return ['channel_id' => $channelId, 'status' => 'not_found'];
        }
        
        return [
            'channel_id' => $channelId,
            'status' => $channel->status,
            'response_time' => $channel->response_time,
            'used_quota' => $channel->used_quota,
            'weight' => $channel->weight,
            'priority' => $channel->priority,
        ];
    }
}