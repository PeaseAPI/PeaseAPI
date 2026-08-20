<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class AuthFlow extends Model
{
    protected $fillable = ['flow_token', 'action', 'payload', 'expires_at'];

    protected function casts(): array
    {
        return ['payload' => 'array', 'expires_at' => 'datetime'];
    }
}
