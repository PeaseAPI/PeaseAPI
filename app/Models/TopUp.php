<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class TopUp extends Model
{
    protected $table = 'top_ups';

    public $timestamps = false;

    protected $fillable = [
        'user_id',
        'amount',
        'money',
        'trade_no',
        'trade_no_internal',
        'status',
        'payment_method',
        'payment_id',
        'created_at',
        'updated_at',
    ];

    protected $casts = [
        'user_id' => 'integer',
        'amount' => 'integer',
        'money' => 'decimal:2',
        'status' => 'integer',
        'created_at' => 'integer',
        'updated_at' => 'integer',
    ];

    public function user()
    {
        return $this->belongsTo(User::class, 'user_id');
    }
}
