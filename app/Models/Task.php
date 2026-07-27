<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Task extends Model
{
    protected $table = 'tasks';
    public $timestamps = false;
    protected $fillable = ['title', 'description', 'type', 'quota', 'limit_count', 'sort_order', 'enabled', 'action', 'action_param', 'expired_at', 'created_at', 'updated_at'];
    protected $casts = ['quota' => 'integer', 'limit_count' => 'integer', 'sort_order' => 'integer', 'enabled' => 'boolean', 'expired_at' => 'integer', 'created_at' => 'integer', 'updated_at' => 'integer'];
}
