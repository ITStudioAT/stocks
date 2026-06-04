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
        'key' => env('POSTMARK_API_KEY'),
    ],

    'resend' => [
        'key' => env('RESEND_API_KEY'),
    ],

    'ses' => [
        'key' => env('AWS_ACCESS_KEY_ID'),
        'secret' => env('AWS_SECRET_ACCESS_KEY'),
        'region' => env('AWS_DEFAULT_REGION', 'us-east-1'),
    ],

    'deutsche_borse' => [
        'id' => env('DEUTSCHE_BORSE_ID'),
        'token' => env('DEUTSCHE_BORSE_TOKEN'),
        'graphql_url' => env('DEUTSCHE_BORSE_GRAPHQL_URL', 'https://api.developer.deutsche-boerse.com/eurex-prod-graphql/'),
    ],

    'stock_identifier_ai' => [
        'provider' => env('STOCK_IDENTIFIER_AI_PROVIDER', 'openai'),
        'model' => env('STOCK_IDENTIFIER_AI_MODEL', 'gpt-4.1-mini'),
    ],

    'stock_price_ai' => [
        'provider' => env('STOCK_PRICE_AI_PROVIDER', env('STOCK_IDENTIFIER_AI_PROVIDER', 'openai')),
        'model' => env('STOCK_PRICE_AI_MODEL', env('STOCK_IDENTIFIER_AI_MODEL', 'gpt-4.1-mini')),
    ],

    'eodhd' => [
        'key' => env('EODHD_API'),
        'base_url' => env('EODHD_BASE_URL', 'https://eodhd.com/api'),
        'connect_timeout' => env('EODHD_CONNECT_TIMEOUT', 5),
        'timeout' => env('EODHD_TIMEOUT', 20),
    ],

    'slack' => [
        'notifications' => [
            'bot_user_oauth_token' => env('SLACK_BOT_USER_OAUTH_TOKEN'),
            'channel' => env('SLACK_BOT_USER_DEFAULT_CHANNEL'),
        ],
    ],

];
