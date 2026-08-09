<?php

return [

    'name' => 'GKStocks',
    'version' => '0.8.7',

    'protected_admin' => [
        'email' => env('SUPER_ADMIN_EMAIL'),
        'first_name' => env('SUPER_ADMIN_FIRST_NAME', 'Protected'),
        'last_name' => env('SUPER_ADMIN_LAST_NAME', 'Administrator'),
    ],
];
