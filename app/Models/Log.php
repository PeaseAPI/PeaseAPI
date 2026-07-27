<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Log extends Model
{
    protected $table = 'logs';
    public $timestamps = false;
    protected $fillable = ['user_id', 'token_id', 'channel_id', 'ability_id', 'type', 'model', 'prompt_tokens', 'completion_tokens', 'quota', 'request_id', 'ip', 'detail', 'created_at'];
    protected $casts = ['user_id' => 'integer', 'token_id' => 'integer', 'channel_id' => 'integer', 'ability_id' => 'integer', 'type' => 'integer', 'prompt_tokens' => 'integer', 'completion_tokens' => 'integer', 'quota' => 'integer', 'created_at' => 'integer'];
}
