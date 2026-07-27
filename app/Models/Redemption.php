<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Redemption extends Model
{
    protected $table = 'redemptions';
    public $timestamps = false;

    protected $fillable = [
        'name',
        'key',
        'status',
        'quota',
        'max_use_count',
        'used_count',
        'used_user_ids',
        'user_id',
        'redeemed_at',
        'expired_at',
        'created_time',
    ];

    protected $casts = [
        'status' => 'integer',
        'quota' => 'integer',
        'max_use_count' => 'integer',
        'used_count' => 'integer',
        'user_id' => 'integer',
        'redeemed_at' => 'integer',
        'expired_at' => 'integer',
        'created_time' => 'integer',
    ];
}