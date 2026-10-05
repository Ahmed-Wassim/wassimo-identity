<?php

return [

    'stateful' => explode(',', env('SANCTUM_STATEFUL_DOMAINS', '')),

    // MUST stay ['web']. This is the session guard Sanctum checks FIRST for
    // SPA cookie auth. Pointing it at 'api' (whose driver IS sanctum) makes
    // Guard::__invoke call guard('api')->user() which re-enters __invoke —
    // infinite recursion → 500 on every authenticated route.
    'guard' => ['web'],

    /*
     * Access tokens are short-lived (15 min default).
     * The client uses the refresh token to obtain a new access token silently.
     *
     * Refresh tokens are long-lived (30 days = 43200 min default).
     * They are stored under the ability "refresh" and are only accepted
     * by POST /api/auth/refresh — nowhere else.
     *
     * The legacy SANCTUM_EXPIRATION key is kept for backwards compatibility
     * but is no longer used by the application. It can be removed once
     * all environments have the two new keys set.
     */
    'access_token_expiration'  => env('ACCESS_TOKEN_EXPIRATION', 15),

    'refresh_token_expiration' => env('REFRESH_TOKEN_EXPIRATION', 43200),

    'token_prefix' => env('SANCTUM_TOKEN_PREFIX', ''),

    'middleware' => [
        'authenticate_session' => Laravel\Sanctum\Http\Middleware\AuthenticateSession::class,
        'encrypt_cookies'      => Illuminate\Cookie\Middleware\EncryptCookies::class,
        'validate_csrf_token'  => Illuminate\Foundation\Http\Middleware\ValidateCsrfToken::class,
    ],

];
