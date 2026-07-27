<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class CustomOAuthProvider extends Model
{
    protected $fillable = ["name", "client_id", "client_secret", "scopes", "authorize_url", "token_url", "userinfo_url", "well_known_url", "icon"];
}
