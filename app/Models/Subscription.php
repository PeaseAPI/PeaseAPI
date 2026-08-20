<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Subscription extends Model
{
    protected $table = 'subscriptions';

    public $timestamps = false;

    protected $fillable = [
        'user_id',
        'plan_id',
        'status',
        'period_start',
        'period_end',
        'quota',
        'used_quota',
        'payment_method',
        'trade_no',
        'auto_renew',
        'created_at',
        'updated_at',
    ];

    protected $casts = [
        'user_id' => 'integer',
        'plan_id' => 'integer',
        'status' => 'integer',
        'period_start' => 'integer',
        'period_end' => 'integer',
        'quota' => 'integer',
        'used_quota' => 'integer',
        'auto_renew' => 'integer',
        'created_at' => 'integer',
        'updated_at' => 'integer',
    ];

    public function user()
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function plan()
    {
        return $this->belongsTo(SubscriptionPlan::class, 'plan_id');
    }

    /**
     * 判断订阅是否有效
     */
    public function isActive(): bool
    {
        return $this->status === 1 && $this->period_end >= time();
    }

    /**
     * 计算剩余配额
     */
    public function remainingQuota(): int
    {
        return max(0, $this->quota - $this->used_quota);
    }
}
