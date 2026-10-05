<?php

// Asymmetric access tokens (ADR-013 v4 idea): identity signs with Ed25519,
// the gateway verifies with the public key only. No per-request /auth/me call.
//
// Keys live as files, NOT in env: PEM-in-env is painful over Docker and leaks
// into logs. Generate with `php artisan jwt:keys` (sodium) or:
//   openssl genpkey -algorithm ed25519 -out private.pem
//   openssl pkey -in private.pem -pubout -out public.pem
// then convert both to base64url of the raw bytes (see jwt:keys --help).
return [

    'private_key_path' => env('JWT_PRIVATE_KEY_PATH', storage_path('app/jwt/private.key')),

    'public_key_path' => env('JWT_PUBLIC_KEY_PATH', storage_path('app/jwt/public.key')),

    // Minutes an access JWT stays valid. The revocation story without a
    // denylist: logout kills the refresh row immediately, a stolen ACCESS
    // token stays usable until this expires. Keep it short (5-15).
    'ttl_minutes' => (int) env('JWT_TTL_MINUTES', 15),

    'issuer' => env('JWT_ISSUER', 'wassimo-identity'),

    'audience' => env('JWT_AUDIENCE', 'wassimo-gateway'),

    // Key id, sent as the JWT `kid` header. Single key today; when rotating,
    // add the new key under a new kid and keep verifying both during overlap.
    'kid' => env('JWT_KID', 'ed25519-1'),

    // Fixed. Never negotiated from the token header — that is the algorithm
    // confusion hole (attacker flips alg to HS256/none). EdDSA only.
    'algorithm' => 'EdDSA',

    // Clock skew tolerance for exp/iat checks, seconds.
    'leeway_seconds' => (int) env('JWT_LEEWAY_SECONDS', 60),
];
