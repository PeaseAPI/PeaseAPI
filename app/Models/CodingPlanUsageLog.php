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
        'prompt_tokens',
        'completion_tokens',
        'total_tokens',
        'request_id',
        'success',
        'error',
        'created_at',
    ];

    protected $casts = [
        'account_id' => 'integer',
        'user_id' => 'integer',
        'channel_id' => 'integer',
        'count' => 'integer',
        'prompt_tokens' => 'integer',
        'completion_tokens' => 'integer',
        'total_tokens' => 'integer',
        'success' => 'boolean',
        'created_at' => 'integer',
    ];

    public function account(): BelongsTo
    {
        return $this->belongsTo(CodingPlanAccount::class, 'account_id');
    }
}