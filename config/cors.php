<?php

$defaultOrigins = [
    '*',
];

$allowedOriginsEnv = env('ALLOWED_ORIGINS');

return [

    'paths' => [
        'api/*',
        'sanctum/csrf-cookie',
    ],

    'allowed_methods' => ['*'],

    'allowed_origins' => $allowedOriginsEnv
        ? array_map('trim', explode(',', $allowedOriginsEnv))
        : $defaultOrigins,

    'allowed_headers' => ['*'],

    'exposed_headers' => [],

    'max_age' => 0,

    'supports_credentials' => true,

];