<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class UserTask extends Model
{
    protected $table = 'user_tasks';
    public $timestamps = false;
    protected $fillable = ['user_id', 'task_id', 'completed_count', 'last_completed_at', 'created_at', 'updated_at'];
    protected $casts = ['user_id' => 'integer', 'task_id' => 'integer', 'completed_count' => 'integer', 'last_completed_at' => 'integer', 'created_at' => 'integer', 'updated_at' => 'integer'];
}
