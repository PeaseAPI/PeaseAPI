<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ExternalIdentityClaim extends Model
{
    protected $fillable = ['user_id', 'provider', 'provider_user_id', 'email', 'username', 'avatar', 'raw_data'];

    protected function casts(): array
    {
        return ['raw_data' => 'array'];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
