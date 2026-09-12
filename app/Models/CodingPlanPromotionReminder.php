<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Coding Plan 活动提醒流水（P2-3，防重发）
 */
class CodingPlanPromotionReminder extends Model
{
    /** 即将到期提醒（remaining <= remind_days×86400） */
    public const KIND_ENDING = 'ending';

    /** 已过期自动处理流水（status 置 2 后记一条） */
    public const KIND_EXPIRED = 'expired';

    /** user_id=0 = 站内公告（Option Notice 追加，全局一份） */
    public const AUDIENCE_GLOBAL = 0;

    public $timestamps = false;

    protected $table = 'coding_plan_promotion_reminders';

    protected $fillable = [
        'promotion_id',
        'kind',
        'user_id',
        'created_at',
    ];

    protected $casts = [
        'promotion_id' => 'integer',
        'user_id' => 'integer',
        'created_at' => 'integer',
    ];
}
