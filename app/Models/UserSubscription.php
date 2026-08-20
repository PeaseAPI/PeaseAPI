<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class UserSubscription extends Model
{
    protected $table = 'user_subscriptions';

    public $timestamps = false;

    protected $fillable = ['user_id', 'plan_id', 'order_id', 'start_at', 'end_at', 'status', 'quota_used', 'quota_total', 'quota_reset_at', 'last_reset_at', 'upgrade_group', 'group_before', 'auto_renew', 'cancelled_at', 'created_at', 'updated_at'];

    protected $casts = ['user_id' => 'integer', 'plan_id' => 'integer', 'order_id' => 'integer', 'start_at' => 'integer', 'end_at' => 'integer', 'quota_used' => 'integer', 'quota_total' => 'integer', 'quota_reset_at' => 'integer', 'last_reset_at' => 'integer', 'auto_renew' => 'boolean', 'cancelled_at' => 'integer', 'created_at' => 'integer', 'updated_at' => 'integer'];
}
