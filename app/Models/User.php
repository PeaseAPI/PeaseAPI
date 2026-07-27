<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Laravel\Sanctum\HasApiTokens;

class User extends Authenticatable
{
    use HasApiTokens, SoftDeletes;

    public $timestamps = false; // We use integer Unix timestamps, not Laravel's auto-timestamps

    protected $table = 'users';
    protected $primaryKey = 'id';

    protected $fillable = [
        'username',
        'password',
        'display_name',
        'avatar',
        'role',
        'status',
        'email',
        'phone',
        'github_id',
        'discord_id',
        'oidc_id',
        'wechat_id',
        'telegram_id',
        'linux_do_id',
        'access_token',
        'quota',
        'used_quota',
        'request_count',
        'group',
        'aff_code',
        'aff_count',
        'aff_quota',
        'aff_history',
        'inviter_id',
        'setting',
        'remark',
        'stripe_customer',
        'created_at',
        'last_login_at',
    ];

    protected $hidden = [
        'password',
        'access_token',
    ];

    protected $casts = [
        'role' => 'integer',
        'status' => 'integer',
        'quota' => 'integer',
        'used_quota' => 'integer',
        'request_count' => 'integer',
        'aff_count' => 'integer',
        'aff_quota' => 'integer',
        'aff_history' => 'integer',
        'created_at' => 'integer',
        'last_login_at' => 'integer',
    ];

    public function tokens()
    {
        return $this->hasMany(Token::class, 'user_id');
    }

    public function logs()
    {
        return $this->hasMany(Log::class, 'user_id');
    }

    public function inviter()
    {
        return $this->belongsTo(User::class, 'inviter_id');
    }

    public function redemptions()
    {
        return $this->hasMany(Redemption::class, 'user_id');
    }

    public function topUps()
    {
        return $this->hasMany(TopUp::class, 'user_id');
    }

    public function subscriptions()
    {
        return $this->hasMany(UserSubscription::class, 'user_id');
    }

    public function isAdmin()
    {
        return $this->role >= 10;
    }

    public function isSuperAdmin()
    {
        return $this->role >= 100;
    }

    public function isRoot(): bool
    {
        return $this->role >= 100;
    }

    public function getAvailableQuota(): int
    {
        return $this->quota - $this->used_quota;
    }
}