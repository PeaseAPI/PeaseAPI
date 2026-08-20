<?php

declare(strict_types=1);
use App\Models\Option;

/**
 * Get system option value
 */
function getOption(string $key, mixed $default = null): mixed
{
    static $cache = [];

    if (! isset($cache[$key])) {
        $option = Option::where('key', $key)->first();
        $cache[$key] = $option?->value ?? $default;
    }

    return $cache[$key];
}

/**
 * Set system option value
 */
function setOption(string $key, mixed $value): void
{
    Option::updateOrCreate(
        ['key' => $key],
        ['value' => $value]
    );
}

/**
 * Format bytes to human readable
 */
function formatBytes(int $bytes): string
{
    $units = ['B', 'KB', 'MB', 'GB', 'TB'];
    $i = 0;
    while ($bytes >= 1024 && $i < count($units) - 1) {
        $bytes /= 1024;
        $i++;
    }

    return round($bytes, 2).' '.$units[$i];
}

/**
 * Generate random API key
 */
function generateApiKey(): string
{
    return 'sk-'.bin2hex(random_bytes(24));
}

/**
 * Get client IP address
 */
function getClientIp(): string
{
    $headers = [
        'HTTP_CF_CONNECTING_IP',
        'HTTP_X_FORWARDED_FOR',
        'HTTP_X_REAL_IP',
        'HTTP_CLIENT_IP',
        'REMOTE_ADDR',
    ];

    foreach ($headers as $header) {
        if (! empty($_SERVER[$header])) {
            $ip = $_SERVER[$header];
            if (strpos($ip, ',') !== false) {
                $ip = trim(explode(',', $ip)[0]);
            }
            if (filter_var($ip, FILTER_VALIDATE_IP)) {
                return $ip;
            }
        }
    }

    return '127.0.0.1';
}
