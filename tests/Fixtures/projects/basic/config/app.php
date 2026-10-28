<?php

return [

    'name' => env('APP_NAME', 'Laravel'),

    'env' => env('APP_ENV', 'production'),

    'debug' => (bool) env('APP_DEBUG', false),

    'url' => env('APP_URL', 'http://localhost'),

    'key' => env('APP_KEY'),

    'x' => env('X', null),

    'timezone' => 'UTC',

    'providers' => [
        'App\Providers\AppServiceProvider',
    ],

];
