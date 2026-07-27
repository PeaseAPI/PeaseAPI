<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * 用户 OAuth 绑定表
 * 对标 new-api model/user_oauth_bindings.go
 */
class UserOAuthBinding extends Model
{
    protected $table = 'user_oauth_bindings';

    public $timestamps = false;

    protected $fillable = [
        'user_id',
        'provider',
        'provider_user_id',
        'created_at',
    ];

    protected $casts = [
        'created_at' => 'integer',
        'user_id' => 'integer',
    ];

    public function user()
    {
        return $this->belongsTo(User::class, 'user_id');
    }
}