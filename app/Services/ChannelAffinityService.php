<?php

declare(strict_types=1);

namespace App\Services;

use App\Setting\OperationSetting\ChannelAffinitySetting;
use Illuminate\Support\Facades\Cache;

/**
 * 渠道亲和性服务 - 用户渠道偏好缓存
 *
 * 统一键格式: channel_affinity:{user_id}:{group}:{model}
 * 值为渠道 ID。
 *
 * 写入: Distributor 在转发成功（响应 < 400）后调用 record()，
 *       仅当 ChannelAffinityEnabled 开启时生效，TTL = ChannelAffinityExpireMinutes 分钟。
 * 读取: Distributor::getChannelFromAffinity 调用 preferredChannelId()，
 *       同 用户+分组+模型 的后续请求优先复用同一渠道。
 * 管理: GET/DELETE /api/option/channel_affinity_cache（Redis 驱动下可枚举/全清，
 *       其它驱动优雅降级；单键清除 ?rule_name={user}:{group}:{model} 不受驱动限制）。
 */
class ChannelAffinityService
{
    /**
     * 亲和缓存键（管理端统计/单键清除依赖此格式）
     */
    public static function key(int $userId, string $group, string $model): string
    {
        return "channel_affinity:{$userId}:{$group}:{$model}";
    }

    /**
     * 读取偏好的渠道 ID（不存在时返回 null）
     */
    public static function preferredChannelId(int $userId, string $group, string $model): mixed
    {
        return Cache::get(self::key($userId, $group, $model));
    }

    /**
     * 记录渠道亲和性（未开启时为 no-op）
     */
    public static function record(int $userId, int $channelId, string $group, string $model): void
    {
        if (! ChannelAffinitySetting::enabled()) {
            return;
        }

        $ttlMinutes = ChannelAffinitySetting::expireMinutes();
        Cache::put(self::key($userId, $group, $model), $channelId, now()->addMinutes($ttlMinutes));
    }
}
