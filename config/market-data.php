<?php

return [
    'default_currency' => env('MARKET_DATA_DEFAULT_CURRENCY', 'EUR'),
    'strict_currency' => env('MARKET_DATA_STRICT_CURRENCY', true),
    'http_timeout_seconds' => env('MARKET_DATA_HTTP_TIMEOUT_SECONDS', 8),
    'connect_timeout_seconds' => env('MARKET_DATA_CONNECT_TIMEOUT_SECONDS', 4),
    'cache_seconds' => env('MARKET_DATA_CACHE_SECONDS', 45),
    'max_sources_per_holding' => env('MARKET_DATA_MAX_SOURCES_PER_HOLDING', 6),
    'realtime_window_seconds' => env('MARKET_DATA_REALTIME_WINDOW_SECONDS', 300),
    'delayed_window_seconds' => env('MARKET_DATA_DELAYED_WINDOW_SECONDS', 1800),
    'max_spread_pct_warning' => env('MARKET_DATA_MAX_SPREAD_PCT_WARNING', 2.0),
    'max_price_jump_pct_warning' => env('MARKET_DATA_MAX_PRICE_JUMP_PCT_WARNING', 25.0),
    'cross_check_tolerance_pct' => env('MARKET_DATA_CROSS_CHECK_TOLERANCE_PCT', 1.5),
    'store_raw_payloads' => env('MARKET_DATA_STORE_RAW_PAYLOADS', false),
    'user_agent' => env('MARKET_DATA_USER_AGENT', 'Mozilla/5.0 compatible portfolio price checker'),
    'sources' => [
        'tradegate' => [
            'enabled' => true,
            'priority' => 10,
            'quality' => 'official_venue',
            'url_templates' => [
                'quote' => 'https://www.tradegatebsx.com/orderbuch.php?isin={ISIN}',
                'timesales' => 'https://www.tradegatebsx.com/orderbuch_umsaetze.php?isin={ISIN}&lang=en',
            ],
        ],
        'onvista_markets' => [
            'enabled' => true,
            'priority' => 20,
            'quality' => 'multi_venue_portal',
        ],
        'finanzen_markets' => [
            'enabled' => true,
            'priority' => 30,
            'quality' => 'multi_venue_portal',
        ],
        'quotrix' => [
            'enabled' => true,
            'priority' => 40,
            'quality' => 'official_venue',
            'search_url' => 'https://www.quotrix.de/wp-json/boeag/v1/search?s={ISIN}',
        ],
        'boerse_stuttgart' => [
            'enabled' => true,
            'priority' => 50,
            'quality' => 'official_venue',
        ],
        'justetf' => [
            'enabled' => true,
            'priority' => 60,
            'quality' => 'etf_portal',
            'url_templates' => [
                'at' => 'https://www.justetf.com/at/etf-profile.html?isin={ISIN}',
                'de' => 'https://www.justetf.com/de/etf-profile.html?isin={ISIN}',
                'en' => 'https://www.justetf.com/en/etf-profile.html?isin={ISIN}',
            ],
        ],
        'deutsche_boerse_live' => [
            'enabled' => true,
            'priority' => 70,
            'quality' => 'exchange_official',
        ],
        'wiener_boerse' => [
            'enabled' => true,
            'priority' => 80,
            'quality' => 'exchange_official',
        ],
        'euronext_live' => [
            'enabled' => true,
            'priority' => 90,
            'quality' => 'exchange_official',
        ],
        'ariva' => [
            'enabled' => true,
            'priority' => 200,
            'quality' => 'finance_portal',
        ],
        'boerse_de' => [
            'enabled' => true,
            'priority' => 210,
            'quality' => 'finance_portal',
        ],
    ],
];
