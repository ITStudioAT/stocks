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
    ],

    'slack' => [
        'notifications' => [
            'bot_user_oauth_token' => env('SLACK_BOT_USER_OAUTH_TOKEN'),
            'channel' => env('SLACK_BOT_USER_DEFAULT_CHANNEL'),
        ],
    ],

];
