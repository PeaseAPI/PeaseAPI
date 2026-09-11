<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Token extends Model
{
    protected $table = 'tokens';

    public $timestamps = false;

    protected $fillable = [
        'user_id', 'key', 'name', 'status', 'quota', 'used_quota', 'remain_quota',
        'unlimited_quota', 'model_limits_enabled', 'model_limits', 'allow_ips',
        'used_today', 'group', 'cross_group_retry', 'setting', 'expired_time',
        'created_time', 'accessed_time',
    ];

    protected $hidden = [
        'key', // Hide API key in responses for security
    ];

    protected $casts = [
        'user_id' => 'integer',
        'quota' => 'integer',
        'used_quota' => 'integer',
        'remain_quota' => 'integer',
        'unlimited_quota' => 'boolean',
        'model_limits_enabled' => 'boolean',
        'used_today' => 'integer',
        'cross_group_retry' => 'boolean',
        'status' => 'integer',
        'expired_time' => 'integer',
        'created_time' => 'integer',
        'accessed_time' => 'integer',
    ];

    public function user()
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function abilities()
    {
        return $this->belongsToMany(Ability::class, 'token_abilities', 'token_id', 'ability_id');
    }

    public function logs()
    {
        return $this->hasMany(Log::class, 'token_id');
    }

    /**
     * Check if token has available quota
     */
    public function hasAvailableQuota(): bool
    {
        if ($this->unlimited_quota) {
            return true;
        }

        // Check expired time
        if ($this->expired_time > 0 && $this->expired_time < time()) {
            return false;
        }

        return $this->remain_quota > 0;
    }

    /**
     * Check if IP is allowed
     */
    public function isIpAllowed(string $ip): bool
    {
        if (empty($this->allow_ips)) {
            return true;
        }

        $allowedIps = explode(',', $this->allow_ips);
        foreach ($allowedIps as $allowedIp) {
            $allowedIp = trim($allowedIp);
            if ($this->ipMatches($ip, $allowedIp)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Simple IP matching (supports wildcard)
     */
    private function ipMatches(string $ip, string $pattern): bool
    {
        if ($pattern === '*' || $pattern === $ip) {
            return true;
        }

        // Support wildcard like 192.168.*
        if (str_ends_with($pattern, '*')) {
            $prefix = rtrim($pattern, '*');

            return str_starts_with($ip, $prefix);
        }

        return false;
    }

    /**
     * Check if model is allowed for this token
     *
     * model_limits 支持两种存储格式（历史数据 + new-api 逗号串）：
     *  - JSON 对象/数组（如 {"gpt-4o":true} 或 ["gpt-4o"]）
     *  - 逗号分隔字符串（如 "gpt-4o,claude-3-5-sonnet"，new-api 风格）
     */
    public function isModelAllowed(string $model): bool
    {
        if (! $this->model_limits_enabled || empty($this->model_limits)) {
            return true;
        }

        $raw = trim((string) $this->model_limits);

        // JSON 格式（对象或数组）
        if (str_starts_with($raw, '{') || str_starts_with($raw, '[')) {
            $limits = json_decode($raw, true);
            if (is_array($limits)) {
                // 对象：键为模型名（支持通配符后缀）
                if (! array_is_list($limits)) {
                    if (isset($limits[$model])) {
                        return $limits[$model] === true || $limits[$model] > 0;
                    }
                    foreach ($limits as $pattern => $allowed) {
                        if (str_ends_with((string) $pattern, '*')
                            && str_starts_with($model, rtrim((string) $pattern, '*'))) {
                            return $allowed === true || $allowed > 0;
                        }
                    }
                } else {
                    // 数组：精确或前缀匹配
                    if (in_array($model, $limits, true)) {
                        return true;
                    }
                    foreach ($limits as $pattern) {
                        if (str_ends_with((string) $pattern, '*')
                            && str_starts_with($model, rtrim((string) $pattern, '*'))) {
                            return true;
                        }
                    }
                }
            }

            return false;
        }

        // 逗号分隔（new-api 风格）
        $allowed = array_filter(array_map('trim', explode(',', $raw)));
        if (in_array($model, $allowed, true)) {
            return true;
        }
        foreach ($allowed as $pattern) {
            if (str_ends_with($pattern, '*') && str_starts_with($model, rtrim($pattern, '*'))) {
                return true;
            }
        }

        return false;
    }

    /**
     * Update access time
     */
    public function updateAccessTime(): void
    {
        $this->accessed_time = time();
        $this->save();
    }
}
