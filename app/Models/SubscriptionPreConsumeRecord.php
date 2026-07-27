<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class SubscriptionPreConsumeRecord extends Model
{
    protected $table = 'subscription_pre_consume_records';
    public $timestamps = false;
    protected $fillable = ['user_id', 'subscription_id', 'quota', 'request_id', 'created_at', 'consumed', 'consumed_at'];
    protected $casts = ['user_id' => 'integer', 'subscription_id' => 'integer', 'quota' => 'integer', 'created_at' => 'integer', 'consumed' => 'boolean', 'consumed_at' => 'integer'];
}
