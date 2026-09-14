<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\SystemTaskStatus;
use App\Enums\SystemTaskType;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SystemTask extends Model
{
    protected $fillable = [
        'type',
        'status',
        'params',
        'result',
        'started_at',
        'completed_at',
        'created_by',
    ];

    protected $casts = [
        'type' => SystemTaskType::class,
        'status' => SystemTaskStatus::class,
        'params' => 'array',
        'result' => 'array',
        'started_at' => 'datetime',
        'completed_at' => 'datetime',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
