<?php

declare(strict_types=1);

namespace App\Setting\OperationSetting;

use App\Services\OptionService;

/**
 * 渠道亲和设置
 *
 * 管理端 channel_affinity_setting.enabled → 规范键 ChannelAffinityEnabled
 * （别名见 OptionService::ALIASES），ChannelAffinityExpireMinutes 为键存活分钟数。
 */
class ChannelAffinitySetting
{
    /**
     * 亲和是否开启（关闭时不写入亲和键，读侧自然退化为普通选择）
     */
    public static function enabled(): bool
    {
        return (bool) OptionService::get('ChannelAffinityEnabled', false);
    }

    /**
     * 亲和键 TTL（分钟），下限 1 分钟
     */
    public static function expireMinutes(): int
    {
        return max(1, (int) OptionService::get('ChannelAffinityExpireMinutes', 60));
    }
}
