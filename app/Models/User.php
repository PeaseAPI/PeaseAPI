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

    /**
     * 头像 URL 访问器
     *
     * 头像文件直接存放于 public/avatars 目录下，数据库中存储相对路径（如 avatars/xxx.png），
     * 不再依赖 storage:link 软链接。OAuth 返回的完整 http(s) 外链则原样返回。
     *
     * 为避免重装/重新部署后 public/avatars 被清空但数据库仍指向旧文件名而产生 404，
     * 本访问器对本地相对路径会校验文件是否真实存在；不存在则返回空，回退到首字母占位符。
     */
    public function getAvatarUrlAttribute(): string
    {
        if (empty($this->avatar)) {
            return '';
        }

        // 历史脏数据：旧版代码可能将 data: URL 写入数据库，浏览器尝试解码会报
        // "Data URL decoding failed"，直接忽略，回退到首字母占位符
        if (stripos($this->avatar, 'data:') === 0) {
            return '';
        }

        // 已经是完整 URL（如 GitHub/Discord 等第三方头像），直接返回
        if (preg_match('#^https?://#i', $this->avatar)) {
            return $this->avatar;
        }

        // 协议相对 URL（//xxx），补全为 https
        if (strpos($this->avatar, '//') === 0) {
            return 'https:' . $this->avatar;
        }

        // 本地相对路径：规范化并校验文件是否存在，避免重装后 404
        $relative = ltrim($this->avatar, '/');
        $absolute = public_path($relative);

        // 同一请求内缓存已校验结果，避免重复磁盘 IO
        static $existsCache = [];
        if (!array_key_exists($absolute, $existsCache)) {
            $existsCache[$absolute] = is_file($absolute);
        }

        if (!$existsCache[$absolute]) {
            return '';
        }

        return '/' . $relative;
    }
}
