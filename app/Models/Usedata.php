<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Usedata extends Model
{
    protected $table = 'usedata';

    protected $fillable = [
        'user_id', 'date', 'request_count', 'prompt_tokens',
        'completion_tokens', 'quota_used',
    ];

    protected $casts = [
        'date' => 'date',
        'request_count' => 'integer',
        'prompt_tokens' => 'integer',
        'completion_tokens' => 'integer',
        'quota_used' => 'integer',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
