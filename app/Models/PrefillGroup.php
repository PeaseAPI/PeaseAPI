<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PrefillGroup extends Model
{
    protected $fillable = ['name', 'description', 'prefills'];

    protected function casts(): array
    {
        return ['prefills' => 'array'];
    }
}
