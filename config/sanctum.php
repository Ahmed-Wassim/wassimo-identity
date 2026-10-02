<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Stateful Domains
    |--------------------------------------------------------------------------
    | This service is a pure API — no browser clients, no SPA cookie sessions.
    | Leave empty; Sanctum will only validate Authorization: Bearer tokens.
    */

    'stateful' => explode(',', env('SANCTUM_STATEFUL_DOMAINS', '')),

    /*
    |--------------------------------------------------------------------------
    | Guard
    |--------------------------------------------------------------------------
    | Map Sanctum to the "api" guard defined in config/auth.php.
    | Spatie permissions are seeded under guard_name="api" — both must match.
    */

    'guard' => ['api'],

    /*
    |--------------------------------------------------------------------------
    | Token Expiration
    |--------------------------------------------------------------------------
    | 1440 minutes = 24 hours. ADR-013 v2: tokens expire; clients must handle
    | re-authentication. Token refresh is deliberately deferred (ADR-013 §Deferred).
    |
    | The prune job runs daily via `php artisan sanctum:prune-expired --hours=24`.
    | Without it, personal_access_tokens grows forever.
    */

    'expiration' => env('SANCTUM_EXPIRATION', 1440),

    'token_prefix' => env('SANCTUM_TOKEN_PREFIX', ''),

    /*
    |--------------------------------------------------------------------------
    | Middleware
    |--------------------------------------------------------------------------
    */

    'middleware' => [
        'authenticate_session' => Laravel\Sanctum\Http\Middleware\AuthenticateSession::class,
        'encrypt_cookies'      => Illuminate\Cookie\Middleware\EncryptCookies::class,
        'validate_csrf_token'  => Illuminate\Foundation\Http\Middleware\ValidateCsrfToken::class,
    ],

];
