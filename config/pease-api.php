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

    // 新闻 / 搜索 API 聚合转发
    'news' => [
        // 单次搜索默认消耗配额（用户配额单位，按次计费）
        'default_quota_per_search' => (int) env('PEASE_API_NEWS_QUOTA_PER_SEARCH', 1),
        // 各 Provider 单次搜索消耗配额（覆盖默认值，键为 provider 标识）
        'quota_per_search' => [
            'google_custom_search' => 1,
            'news_api' => 1,
            'tavily' => 2,
            'exa' => 2,
        ],
        // 上游请求超时（秒）
        'timeout' => (int) env('PEASE_API_NEWS_TIMEOUT', 30),
        // 默认返回结果数量上限
        'default_max_results' => 10,
        // 允许的最大结果数量
        'max_results_limit' => 50,
        // 是否在响应中返回原始上游数据（调试用）
        'include_raw' => (bool) env('PEASE_API_NEWS_INCLUDE_RAW', false),
    ],
];
