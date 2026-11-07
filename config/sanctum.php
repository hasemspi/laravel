<?php

return [
    'stateful' => array_values(array_filter(array_map('trim', explode(',', (string) env('SANCTUM_STATEFUL_DOMAINS', 'localhost:3000,127.0.0.1:3000'))))),
    'guard' => ['web'],
    'expiration' => null,
    'middleware' => [
        'encrypt_cookies' => Illuminate\Cookie\Middleware\EncryptCookies::class,
        'validate_csrf_token' => Illuminate\Foundation\Http\Middleware\ValidateCsrfToken::class,
        'authenticate_session' => Laravel\Sanctum\Http\Middleware\AuthenticateSession::class,
    ],
];
