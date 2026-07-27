<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Cache;

class Option extends Model
{
    protected $table = 'options';
    public $timestamps = false;
    protected $primaryKey = 'key';
    protected $keyType = 'string';
    public $incrementing = false;
    protected $fillable = ['key', 'value'];
    protected $casts = [];

    /**
     * Get an option value by key with optional default.
     *
     * IMPORTANT: We must NOT cache `false` for missing keys, otherwise newly inserted
     * defaults / installations will not be picked up until the cache is cleared manually.
     * Use a short-TTL cache so newly seeded rows are picked up automatically.
     */
    public static function get(string $key, mixed $default = null): mixed
    {
        $value = Cache::remember("option:{$key}", 60, function () use ($key) {
            $row = static::where('key', $key)->value('value');
            return $row; // raw string or null
        });

        if ($value === null || $value === false) {
            return $default;
        }

        return self::castValue($value);
    }

    /**
     * Set an option value and clear cache.
     */
    public static function set(string $key, mixed $value): void
    {
        $stored = is_array($value) ? json_encode($value, JSON_UNESCAPED_UNICODE) : (string) $value;
        static::updateOrCreate(['key' => $key], ['value' => $stored]);
        Cache::forget("option:{$key}");
    }

    /**
     * Load all options as key=>value map.
     */
    public static function loadAll(): array
    {
        return static::query()->pluck('value', 'key')->map(fn ($v) => self::castValue($v))->all();
    }

    /**
     * Clear the option cache (option key or all).
     */
    public static function clearCache(?string $key = null): void
    {
        if ($key !== null) {
            Cache::forget("option:{$key}");
            return;
        }
        try {
            $cache = Cache::getStore();
            if (method_exists($cache, 'getRedis')) {
                $redis = $cache->getRedis();
                $prefix = config('cache.prefix', '') . ':option:';
                $cursor = null;
                do {
                    [$cursor, $keys] = $redis->scan($cursor ?? 0, ['match' => "{$prefix}*", 'count' => 200]);
                    if (!empty($keys)) {
                        $redis->del($keys);
                    }
                } while (!empty($cursor) && $cursor !== '0');
            }
        } catch (\Throwable $e) {
            // ignore cache errors
        }
    }

    private static function castValue(mixed $value): mixed
    {
        if (!is_string($value)) {
            return $value;
        }
        $trimmed = trim($value);
        if (($trimmed[0] ?? '') === '{' || ($trimmed[0] ?? '') === '[') {
            $decoded = json_decode($value, true);
            if (json_last_error() === JSON_ERROR_NONE) {
                return $decoded;
            }
        }
        if ($value === 'true') return true;
        if ($value === 'false') return false;
        return $value;
    }
}
