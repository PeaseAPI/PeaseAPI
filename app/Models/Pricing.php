<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Pricing extends Model
{
    protected $table = 'pricings';
    public $timestamps = false;
    protected $fillable = ['method', 'price', 'original_price', 'quota', 'label', 'stripe_price_id', 'enabled', 'sort_order', 'created_at', 'updated_at'];
    protected $casts = ['price' => 'float', 'original_price' => 'float', 'quota' => 'integer', 'enabled' => 'boolean', 'sort_order' => 'integer', 'created_at' => 'integer', 'updated_at' => 'integer'];
}
