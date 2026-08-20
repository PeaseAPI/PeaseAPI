<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class MissingModel extends Model
{
    protected $fillable = ['model_name', 'channel_id', 'reported_at'];

    protected function casts(): array
    {
        return ['reported_at' => 'datetime'];
    }
}
