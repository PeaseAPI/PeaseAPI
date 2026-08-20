<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ModelExtra extends Model
{
    protected $fillable = ['model_name', 'input_price', 'output_price', 'max_tokens', 'max_context', 'vision', 'function_call', 'streaming', 'description'];
}
