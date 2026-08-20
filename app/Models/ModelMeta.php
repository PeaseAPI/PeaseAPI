<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ModelMeta extends Model
{
    protected $fillable = ['model_name', 'owner_id', 'description', 'tags'];

    public function owner(): BelongsTo
    {
        return $this->belongsTo(ModelOwner::class, 'owner_id');
    }
}
