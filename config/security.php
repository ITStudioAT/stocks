<?php

$applicationHost = parse_url((string) env('APP_URL', 'http://localhost'), PHP_URL_HOST);
$trustedHosts = explode(',', (string) env('APP_TRUSTED_HOSTS', $applicationHost ?: 'localhost'));
$trustedProxies = explode(',', (string) env('TRUSTED_PROXIES', ''));

return [
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
