<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class CasbinRule extends Model
{
    public $timestamps = false;
    protected $fillable = ["ptype", "v0", "v1", "v2", "v3", "v4", "v5"];
}
