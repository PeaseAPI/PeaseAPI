<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class UserTaskRecord extends Model
{
    protected $table = 'user_task_records';
    public $timestamps = false;
    protected $fillable = ['user_id', 'task_id', 'quota', 'created_at'];
    protected $casts = ['user_id' => 'integer', 'task_id' => 'integer', 'quota' => 'integer', 'created_at' => 'integer'];
}
