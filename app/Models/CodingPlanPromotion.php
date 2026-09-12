<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * Coding Plan 厂商促销/价格变动/模型退市活动（P2-1）
 */
class CodingPlanPromotion extends Model
{
    /** 活动类型 */
    public const KIND_DISCOUNT = 'discount';

    public const KIND_FREE = 'free';

    public const KIND_PRICE_CHANGE = 'price_change';

    public const KIND_MODEL_RETIREMENT = 'model_retirement';

    public const KINDS = [
        self::KIND_DISCOUNT,
        self::KIND_FREE,
        self::KIND_PRICE_CHANGE,
        self::KIND_MODEL_RETIREMENT,
    ];

    public const STATUS_ENABLED = 1;

    public const STATUS_DISABLED = 0;

    public $timestamps = false;

    protected $table = 'coding_plan_promotions';

    protected $fillable = [
        'vendor',
        'kind',
        'title',
        'description',
        'discount',
        'starts_at',
        'ends_at',
        'source_url',
        'status',
        'remind_days',
        'sort',
        'remark',
        'created_at',
        'updated_at',
    ];

    protected $casts = [
        'discount' => 'float',
        'starts_at' => 'integer',
        'ends_at' => 'integer',
        'status' => 'integer',
        'remind_days' => 'integer',
        'sort' => 'integer',
        'created_at' => 'integer',
        'updated_at' => 'integer',
    ];

    /**
     * 活动展示状态推导：
     * - expired   已结束（ends_at 非空且 < now）
     * - scheduled 未开始（starts_at > now）
     * - ongoing   进行中（含 ends_at 为空=官方未公布的长期活动）
     */
    public function displayState(int $now): string
    {
        if ($this->ends_at !== null && $this->ends_at > 0 && $this->ends_at < $now) {
            return 'expired';
        }
        if ($this->starts_at > $now) {
            return 'scheduled';
        }

        return 'ongoing';
    }

    /** 剩余秒数（ends_at 未公布返回 null，前台不显示倒计时） */
    public function remainingSeconds(int $now): ?int
    {
        if ($this->ends_at === null || $this->ends_at <= 0) {
            return null;
        }

        return max(0, $this->ends_at - $now);
    }

    /** 启用中的活动（前台/提醒扫描口径） */
    public function scopeEnabled(Builder $query): Builder
    {
        return $query->where('status', self::STATUS_ENABLED);
    }
}
