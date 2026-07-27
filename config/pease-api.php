<?php

return [
    'version' => env('PEASE_API_VERSION', '1.0.0'),
    'theme' => env('PEASE_API_THEME', 'default'),
    'session_secret' => env('PEASE_API_SESSION_SECRET', 'change-me'),
    'sync_frequency' => env('PEASE_API_SYNC_FREQUENCY', 60),
    'memory_cache_enabled' => env('PEASE_API_MEMORY_CACHE_ENABLED', true),
    'batch_update_enabled' => env('PEASE_API_BATCH_UPDATE_ENABLED', false),
    'batch_update_interval' => env('PEASE_API_BATCH_UPDATE_INTERVAL', 5),
    'default_group' => 'default',
    'pre_consumed_quota' => 500,
    'channel_update_frequency' => env('CHANNEL_UPDATE_FREQUENCY', 60),
    'channel_test_timeout' => 30,
    'channel' => [
        'max_fail_count' => env('PEASE_API_CHANNEL_MAX_FAIL_COUNT', 5),
    ],
    'relay' => [
        'timeout' => 120,
        'max_retries' => 3,
        'retry_delay' => 1000,
    ],
    'billing' => [
        'model_ratios' => [],
        'group_ratios' => [],
        'prices' => [],
    ],
    'oauth' => [
        'github' => ['enabled' => false, 'client_id' => '', 'client_secret' => '', 'redirect' => '/auth/github/callback'],
        'discord' => ['enabled' => false, 'client_id' => '', 'client_secret' => '', 'redirect' => '/auth/discord/callback'],
        'telegram' => ['enabled' => false, 'bot_token' => ''],
        'wechat' => ['enabled' => false, 'app_id' => '', 'app_secret' => ''],
    ],
    'payment' => [
        'stripe' => ['enabled' => false, 'secret_key' => '', 'webhook_secret' => ''],
        'creem' => ['enabled' => false, 'api_key' => ''],
        'waffo' => ['enabled' => false, 'api_key' => ''],
    ],
    'analytics' => [
        'umami' => ['enabled' => false, 'website_id' => '', 'script_url' => 'https://analytics.umami.is/script.js'],
        'google' => ['enabled' => false, 'measurement_id' => ''],
    ],
    'turnstile' => ['enabled' => false, 'site_key' => '', 'secret_key' => ''],
];