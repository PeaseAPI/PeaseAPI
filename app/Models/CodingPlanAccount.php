<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\Cache;

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

    /** 计费模式：按次提交（每次请求消耗 1 次提交） */
    public const BILLING_MODE_PER_REQUEST = 1;

    /** 计费模式：按积分折算（依据模型折算比率表换算单位消耗） */
    public const BILLING_MODE_CREDIT = 2;

    protected $table = 'coding_plan_accounts';

    public $timestamps = false;

    protected $fillable = [
        'vendor',
        'billing_mode',
        'unit_name',
        'unit_exchange_rate',
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
        'billing_mode' => 'integer',
        'unit_exchange_rate' => 'float',
        'expires_at' => 'integer',
        'quota_5h' => 'float',
        'used_5h' => 'float',
        'reset_5h_at' => 'integer',
        'quota_weekly' => 'float',
        'used_weekly' => 'float',
        'reset_weekly_at' => 'integer',
        'quota_monthly' => 'float',
        'used_monthly' => 'float',
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
     * 账号是否在指定周期内仍有可用配额（可被调度）
     */
    public function hasAvailableQuota(): bool
    {
        if ($this->status !== self::STATUS_ENABLED) {
            // 停用 / 已耗尽的账号不参与调度（耗尽账号在窗口到期后自动恢复）
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
     * 计数单位显示名（次/点/积分），空则根据计费模式推断
     */
    public function unitDisplayName(): string
    {
        if ($this->unit_name !== '') {
            return $this->unit_name;
        }

        return $this->billing_mode === self::BILLING_MODE_CREDIT ? '积分' : '次';
    }

    /**
     * 是否按积分折算计费
     */
    public function isCreditBilling(): bool
    {
        return $this->billing_mode === self::BILLING_MODE_CREDIT;
    }

    /**
     * 账号生效的「供应商单位 → 平台积分」汇率
     *
     * 级联：账号级 > 供应商默认 > 兜底 1。
     * 账号 rate 为 0 表示「跟随供应商默认」（见 storeAccount 注释），
     * 与 CodingPlanRatioService::exchangeRate() 保持同一口径。
     */
    public function effectiveExchangeRate(): float
    {
        $rate = (float) $this->unit_exchange_rate;
        if ($rate > 0) {
            return $rate;
        }

        /** @var CodingPlanVendor|null $vendor */
        $vendor = Cache::remember(
            'coding_plan_vendor:'.$this->vendor,
            300,
            fn () => CodingPlanVendor::query()->where('code', $this->vendor)->first()
        );

        if ($vendor !== null && (float) $vendor->unit_exchange_rate > 0) {
            return (float) $vendor->unit_exchange_rate;
        }

        return 1.0;
    }

    /**
     * 供应商原生单位 → 平台积分折算（统一折算池口径）
     */
    public function toCredits(float $units): float
    {
        return round($units * max(0.000001, $this->effectiveExchangeRate()), 4);
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
