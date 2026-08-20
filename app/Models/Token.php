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
     */
    public function isModelAllowed(string $model): bool
    {
        if (! $this->model_limits_enabled || empty($this->model_limits)) {
            return true;
        }

        $limits = json_decode($this->model_limits, true);
        if (! $limits || ! is_array($limits)) {
            return true;
        }

        // Check exact match
        if (isset($limits[$model])) {
            return $limits[$model] === true || $limits[$model] > 0;
        }

        // Check prefix match (e.g., "gpt-4*" matches "gpt-4o")
        foreach ($limits as $pattern => $allowed) {
            if (str_ends_with($pattern, '*')) {
                $prefix = rtrim($pattern, '*');
                if (str_starts_with($model, $prefix)) {
                    return $allowed === true || $allowed > 0;
                }
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
