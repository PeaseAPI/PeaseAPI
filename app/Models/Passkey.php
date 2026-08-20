<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Passkey 凭证模型
 * 对标 new-api model/passkeys.go
 */
class Passkey extends Model
{
    protected $table = 'passkeys';

    public $timestamps = false;

    protected $fillable = [
        'user_id',
        'name',
        'credential_id',
        'public_key',
        'counter',
        'device_type',
        'backed_up',
        'transports',
        'created_at',
        'updated_at',
    ];

    protected $casts = [
        'user_id' => 'integer',
        'counter' => 'integer',
        'backed_up' => 'boolean',
        'created_at' => 'integer',
        'updated_at' => 'integer',
        'transports' => 'array',
    ];

    public function user()
    {
        return $this->belongsTo(User::class, 'user_id');
    }
}
