<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Third Party Services
    |--------------------------------------------------------------------------
    |
    | This file is for storing the credentials for third party services such
    | as Mailgun, Postmark, AWS and more. This file provides the de facto
    | location for this type of information, allowing packages to have
    | a conventional file to locate the various service credentials.
    |
    */

    'postmark' => [
        'key' => env('POSTMARK_API_KEY', env('POSTMARK_TOKEN')),
    ],

    'super_admin' => [
        'password' => env('SA_PW'),
    ],

    'cloudways' => [
        'connection' => env('CLOUDWAYS_CONNECTION'),
        'host' => env('CLOUDWAYS_HOST'),
        'port' => env('CLOUDWAYS_PORT', 3306),
        'database' => env('CLOUDWAYS_DATABASE'),
        'username' => env('CLOUDWAYS_USERNAME'),
        'password' => env('CLOUDWAYS_PASSWORD'),
        'connect_timeout' => env('CLOUDWAYS_CONNECT_TIMEOUT', 5),
        'sync_tables' => [
            'depot_transactions',
            'depots',
            'eodhd_exchanges',
            'index_watch_item_intraday_candles',
            'index_watch_item_prices',
            'index_watch_item_realtime_prices',
            'index_watch_items',
            'stock_holding_daily_prices',
            'stock_holding_intraday_candles',
            'stock_holding_intraday_prices',
            'stock_holding_source_candidates',
            'stock_holdings',
            'stock_price_quotes',
            'stock_prices',
            'stock_realtime_prices',
        ],
        'deployment' => [
            'base_url' => env('CLOUDWAYS_API_BASE_URL', 'https://api.cloudways.com/api/v2'),
            'access_token' => env('CLOUDWAYS_API_ACCESS_TOKEN'),
            'server_id' => env('CLOUDWAYS_SERVER_ID'),
            'app_id' => env('CLOUDWAYS_APP_ID'),
            'branch' => env('CLOUDWAYS_DEPLOY_BRANCH', 'main'),
            'deploy_path' => env('CLOUDWAYS_DEPLOY_PATH'),
            'connect_timeout' => env('CLOUDWAYS_API_CONNECT_TIMEOUT', 5),
            'timeout' => env('CLOUDWAYS_API_TIMEOUT', 20),
            'operation_timeout' => env('CLOUDWAYS_DEPLOY_OPERATION_TIMEOUT', 600),
            'poll_interval' => env('CLOUDWAYS_DEPLOY_POLL_INTERVAL', 3),
        ],
    ],

    'resend' => [
        'key' => env('RESEND_API_KEY'),
    ],

    'ses' => [
        'key' => env('AWS_ACCESS_KEY_ID'),
        'secret' => env('AWS_SECRET_ACCESS_KEY'),
        'region' => env('AWS_DEFAULT_REGION', 'us-east-1'),
    ],

    'eodhd' => [
        'key' => env('EODHD_API'),
        'base_url' => env('EODHD_BASE_URL', 'https://eodhd.com/api'),
        'connect_timeout' => env('EODHD_CONNECT_TIMEOUT', 5),
        'timeout' => env('EODHD_TIMEOUT', 20),
        'calls_per_hour' => env('EODHD_CALLS_PER_HOUR', 1000),
        'calls_per_day' => env('EODHD_CALLS_PER_DAY', 100000),
        'calls_used_today' => env('EODHD_CALLS_USED_TODAY', 0),
        'no_data_retry_hours' => env('EODHD_NO_DATA_RETRY_HOURS', 12),
    ],

    'slack' => [
        'notifications' => [
            'bot_user_oauth_token' => env('SLACK_BOT_USER_OAUTH_TOKEN'),
            'channel' => env('SLACK_BOT_USER_DEFAULT_CHANNEL'),
        ],
    ],

];
