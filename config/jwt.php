<?php

return [
    'secret' => env('JWT_SECRET') ?: env('APP_KEY'),
    'ttl' => (int) env('JWT_TTL', 1440),
    'issuer' => env('APP_URL', 'http://localhost'),
    'cookie_name' => env('JWT_COOKIE_NAME', 'mawifarm_token'),
    'cookie_secure' => env('JWT_COOKIE_SECURE', false),
    'cookie_same_site' => env('JWT_COOKIE_SAME_SITE', 'lax'),
];
