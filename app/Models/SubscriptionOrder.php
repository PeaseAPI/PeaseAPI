<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class SubscriptionOrder extends Model
{
    protected $table = 'subscription_orders';
    public $timestamps = false;
    protected $fillable = ['user_id', 'plan_id', 'trade_no', 'amount', 'currency', 'status', 'payment_method', 'payment_provider', 'period_start', 'period_end', 'created_at', 'paid_at', 'cancelled_at'];
    protected $casts = ['user_id' => 'integer', 'plan_id' => 'integer', 'amount' => 'float', 'period_start' => 'integer', 'period_end' => 'integer', 'created_at' => 'integer', 'paid_at' => 'integer', 'cancelled_at' => 'integer'];
}
