<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Checkin extends Model
{
    protected $table = 'checkins';

    public $timestamps = false;

    protected $fillable = ['user_id', 'day', 'quota', 'created_at'];

    protected $casts = ['user_id' => 'integer', 'day' => 'integer', 'quota' => 'integer', 'created_at' => 'integer'];
}
