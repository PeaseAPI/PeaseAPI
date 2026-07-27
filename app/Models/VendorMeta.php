<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class VendorMeta extends Model
{
    protected $fillable = ["name", "description", "icon", "website"];
}
