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
     * Aggregate cache key holding the whole raw options map (see loadAll()).
     */
    public const AGGREGATE_CACHE_KEY = 'option:__all__';

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
        $stored = match (true) {
            is_array($value) => json_encode($value, JSON_UNESCAPED_UNICODE),
            is_bool($value) => $value ? 'true' : 'false',
            default => (string) $value,
        };
        static::updateOrCreate(['key' => $key], ['value' => $stored]);
        Cache::forget("option:{$key}");
        Cache::forget(self::AGGREGATE_CACHE_KEY);
    }

    /**
     * Load all options as key=>value map.
     */
    public static function loadAll(): array
    {
        // Aggregate cache: one store read replaces a full-table query per call.
        // Stores RAW strings only; castValue runs per read (cheap, in-memory).
        $stored = Cache::remember(self::AGGREGATE_CACHE_KEY, 60, fn () => static::query()->pluck('value', 'key')->all());

        return array_map(fn ($v) => self::castValue($v), $stored);
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
        // The aggregate map must be dropped on every store driver, not just Redis.
        Cache::forget(self::AGGREGATE_CACHE_KEY);
        try {
            $cache = Cache::getStore();
            if (method_exists($cache, 'getRedis')) {
                $redis = $cache->getRedis();
                $prefix = config('cache.prefix', '').':option:';
                $cursor = null;
                do {
                    [$cursor, $keys] = $redis->scan($cursor ?? 0, ['match' => "{$prefix}*", 'count' => 200]);
                    if (! empty($keys)) {
                        $redis->del($keys);
                    }
                } while (! empty($cursor) && $cursor !== '0');
            }
        } catch (\Throwable $e) {
            // ignore cache errors
        }
    }

    private static function castValue(mixed $value): mixed
    {
        if (! is_string($value)) {
            return $value;
        }
        $trimmed = trim($value);
        if (($trimmed[0] ?? '') === '{' || ($trimmed[0] ?? '') === '[') {
            $decoded = json_decode($value, true);
            if (json_last_error() === JSON_ERROR_NONE) {
                return $decoded;
            }
        }
        if ($value === 'true') {
            return true;
        }
        if ($value === 'false') {
            return false;
        }

        return $value;
    }
}
