<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class SystemInstance extends Model
{
    protected $fillable = ["node_name", "ip", "capabilities", "last_heartbeat"];

    protected function casts(): array
    {
        return ["capabilities" => "array", "last_heartbeat" => "datetime"];
    }
}
