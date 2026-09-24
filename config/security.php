<?php

$applicationHost = parse_url((string) env('APP_URL', 'http://localhost'), PHP_URL_HOST);
$trustedHosts = explode(',', (string) env('APP_TRUSTED_HOSTS', $applicationHost ?: 'localhost'));
$trustedProxies = explode(',', (string) env('TRUSTED_PROXIES', ''));

return [
    'preview' => [
        'enabled' => (bool) env('STOCKS_PREVIEW', false),
        'source_app_id' => env('PREVIEW_SOURCE_APP_ID'),
        'target_app_id' => env('PREVIEW_TARGET_APP_ID'),
        'server_id' => env('PREVIEW_SERVER_ID'),
        'source_database' => env('PREVIEW_SOURCE_DATABASE'),
        'source_database_user' => env('PREVIEW_SOURCE_DATABASE_USER'),
        'source_key_sha256' => env('PREVIEW_SOURCE_KEY_SHA256'),
        'source_url' => env('PREVIEW_SOURCE_URL'),
        'target_root' => env('PREVIEW_TARGET_ROOT'),
        'access_password_hash' => env('PREVIEW_ACCESS_PASSWORD_HASH'),
        'control_enabled' => (bool) env('PREVIEW_CONTROL_ENABLED', false),
        'control_key' => env('PREVIEW_CONTROL_KEY'),
        'control_url' => env('PREVIEW_CONTROL_URL'),
    ],

    'trusted_hosts' => array_values(array_unique(array_filter(array_map(
        fn (string $host): string => trim($host),
        $trustedHosts,
    )))),

    'trusted_proxies' => array_values(array_unique(array_filter(array_map(
        fn (string $proxy): string => trim($proxy),
        $trustedProxies,
    )))),

    'headers' => [
        'hsts_max_age' => (int) env('SECURITY_HSTS_MAX_AGE', 31_536_000),
    ],
];
