<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class UsedataFlow extends Model
{
    protected $table = 'usedata_flows';

    protected $fillable = [
        'user_id', 'model_name', 'type', 'quota_used',
        'prompt_tokens', 'completion_tokens',
    ];

    protected $casts = [
        'quota_used' => 'integer',
        'prompt_tokens' => 'integer',
        'completion_tokens' => 'integer',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
