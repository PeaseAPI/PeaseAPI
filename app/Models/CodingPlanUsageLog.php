<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Coding Plan 账号使用流水
 */
class CodingPlanUsageLog extends Model
{
    protected $table = 'coding_plan_usage_logs';

    public $timestamps = false;

    protected $fillable = [
        'account_id',
        'vendor',
        'user_id',
        'channel_id',
        'model',
        'count',
        'units',
        'credits',
        'prompt_tokens',
        'completion_tokens',
        'total_tokens',
        'request_id',
        'success',
        'error',
        'meta',
        'created_at',
    ];

    protected $casts = [
        'account_id' => 'integer',
        'user_id' => 'integer',
        'channel_id' => 'integer',
        'count' => 'float',
        'units' => 'float',
        'credits' => 'float',
        'prompt_tokens' => 'integer',
        'completion_tokens' => 'integer',
        'total_tokens' => 'integer',
        'success' => 'boolean',
        'meta' => 'array',
        'created_at' => 'integer',
    ];

    public function account(): BelongsTo
    {
        return $this->belongsTo(CodingPlanAccount::class, 'account_id');
    }
}
