<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Pricing extends Model
{
    protected $table = 'pricings';

    public $timestamps = false;

    protected $fillable = ['model_name', 'description', 'icon', 'group', 'input_price', 'output_price', 'sort_order', 'created_at', 'updated_at'];

    protected $casts = ['input_price' => 'float', 'output_price' => 'float', 'sort_order' => 'integer'];
}
