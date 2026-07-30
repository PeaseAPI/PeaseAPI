<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Coding Plan 上游账号池
 *
 * 将多个供应商的 coding plan 账号（按 5 小时 / 周 / 月提交次数计费）
 * 纳入中转站统一管理，支持到期时间、使用计数、月使用率阈值与自动切换。
 */
class CodingPlanAccount extends Model
{
    public const STATUS_DISABLED = 0;
    public const STATUS_ENABLED = 1;
    public const STATUS_EXHAUSTED = 2;

    protected $table = 'coding_plan_accounts';

    public $timestamps = false;

    protected $fillable = [
        'vendor',
        'account_name',
        'api_key',
        'base_url',
        'expires_at',
        'quota_5h',
        'used_5h',
        'reset_5h_at',
        'quota_weekly',
        'used_weekly',
        'reset_weekly_at',
        'quota_monthly',
        'used_monthly',
        'reset_monthly_at',
        'monthly_usage_threshold',
        'priority',
        'status',
        'channel_id',
        'remark',
        'last_used_at',
        'created_at',
        'updated_at',
    ];

    protected $casts = [
        'expires_at' => 'integer',
        'quota_5h' => 'integer',
        'used_5h' => 'integer',
        'reset_5h_at' => 'integer',
        'quota_weekly' => 'integer',
        'used_weekly' => 'integer',
        'reset_weekly_at' => 'integer',
        'quota_monthly' => 'integer',
        'used_monthly' => 'integer',
        'reset_monthly_at' => 'integer',
        'monthly_usage_threshold' => 'integer',
        'priority' => 'integer',
        'status' => 'integer',
        'channel_id' => 'integer',
        'last_used_at' => 'integer',
        'created_at' => 'integer',
        'updated_at' => 'integer',
    ];

    protected $hidden = [
        'api_key',
    ];

    public function usageLogs(): HasMany
    {
        return $this->hasMany(CodingPlanUsageLog::class, 'account_id');
    }

    /**
     * 账号是否已过期
     */
    public function isExpired(): bool
    {
        return $this->expires_at > 0 && $this->expires_at <= time();
    }

    /**
     * 账号是否在指定周期内仍有可用配额
     */
    public function hasAvailableQuota(): bool
    {
        if ($this->status === self::STATUS_DISABLED) {
            return false;
        }
        if ($this->isExpired()) {
            return false;
        }
        // 5h 配额（>0 表示启用该周期限制）
        if ($this->quota_5h > 0 && $this->used_5h >= $this->quota_5h) {
            return false;
        }
        if ($this->quota_weekly > 0 && $this->used_weekly >= $this->quota_weekly) {
            return false;
        }
        if ($this->quota_monthly > 0 && $this->used_monthly >= $this->quota_monthly) {
            return false;
        }

        return true;
    }

    /**
     * 月使用率（0-100），quota_monthly=0 时返回 0
     */
    public function monthlyUsageRate(): int
    {
        if ($this->quota_monthly <= 0) {
            return 0;
        }
        return (int) min(100, (int) round($this->used_monthly * 100 / $this->quota_monthly));
    }

    /**
     * 是否超过月使用率阈值
     */
    public function exceedsMonthlyThreshold(): bool
    {
        $threshold = $this->monthly_usage_threshold ?: 100;

        return $this->monthlyUsageRate() >= $threshold;
    }

    /**
     * 返回各周期剩余次数
     */
    public function remaining(): array
    {
        return [
            '5h' => $this->quota_5h > 0 ? max(0, $this->quota_5h - $this->used_5h) : -1,
            'weekly' => $this->quota_weekly > 0 ? max(0, $this->quota_weekly - $this->used_weekly) : -1,
            'monthly' => $this->quota_monthly > 0 ? max(0, $this->quota_monthly - $this->used_monthly) : -1,
        ];
    }

    /**
     * 获取解密后的 API Key（若已加密）
     */
    public function getApiKeyPlain(): ?string
    {
        if (empty($this->api_key)) {
            return null;
        }
        // 简单的 base64 可逆存储；生产环境建议替换为 Laravel Encrypter
        $decoded = base64_decode((string) $this->api_key, true);

        return $decoded === false ? $this->api_key : $decoded;
    }
}