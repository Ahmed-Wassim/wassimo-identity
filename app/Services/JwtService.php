<?php

namespace App\Services;

use App\Models\User;
use Firebase\JWT\JWT;
use Firebase\JWT\Key;
use Illuminate\Support\Str;

/**
 * Issues and verifies short-lived Ed25519 access JWTs.
 *
 * Refresh tokens stay opaque Sanctum rows (revocable by DELETE); only the
 * access token becomes a JWT so the gateway can verify locally with the
 * public key and skip the per-request /auth/me call.
 */
class JwtService
{
    private const ALGORITHM = 'EdDSA';

    private ?string $privateKey = null;

    private ?string $publicKey = null;

    /**
     * @return array{token: string, expires_at: string}
     */
    public function issue(User $user, string $deviceName): array
    {
        $now = time();
        $ttl = (int) config('jwt.ttl_minutes', 15);
        $expiresAt = $now + $ttl * 60;

        // Roles/permissions are baked in for the gateway's X-User-* headers.
        // They can go stale mid-session; /auth/me always reads fresh data.
        $payload = [
            'iss' => config('jwt.issuer'),
            'aud' => config('jwt.audience'),
            'sub' => (string) $user->id,
            'device' => $deviceName,
            'roles' => $user->getRoleNames()->values()->all(),
            'permissions' => $user->getAllPermissions()->pluck('name')->values()->all(),
            'iat' => $now,
            'exp' => $expiresAt,
            'jti' => (string) Str::uuid(),
        ];

        $token = JWT::encode($payload, $this->privateKey(), self::ALGORITHM, config('jwt.kid'));

        return [
            'token' => $token,
            'expires_at' => gmdate('c', $expiresAt),
        ];
    }

    /**
     * @throws \Throwable on any failure: bad signature, wrong alg/kid, expired, bad iss/aud.
     */
    public function verify(string $token): object
    {
        JWT::$leeway = (int) config('jwt.leeway_seconds', 60);

        $decoded = JWT::decode($token, new Key($this->publicKey(), self::ALGORITHM));

        // firebase/php-jwt checks exp/iat/nbf but NOT iss/aud — enforce here.
        // Without this, a JWT from another issuer using the same key verifies.
        if (($decoded->iss ?? null) !== config('jwt.issuer')) {
            throw new \UnexpectedValueException('invalid issuer');
        }

        $aud = $decoded->aud ?? null;
        $aud = is_array($aud) ? $aud : [$aud];
        if (! in_array(config('jwt.audience'), $aud, true)) {
            throw new \UnexpectedValueException('invalid audience');
        }

        return $decoded;
    }

    private function privateKey(): string
    {
        return $this->privateKey ??= $this->readKey('jwt.private_key_path', 'private');
    }

    private function publicKey(): string
    {
        return $this->publicKey ??= $this->readKey('jwt.public_key_path', 'public');
    }

    private function readKey(string $configKey, string $which): string
    {
        $path = (string) config($configKey);

        if (! is_file($path) || ! is_readable($path)) {
            throw new \RuntimeException(
                "JWT {$which} key not found at {$path}. Run `php artisan jwt:keys`."
            );
        }

        $contents = trim((string) file_get_contents($path));

        if ($contents === '') {
            throw new \RuntimeException("JWT {$which} key at {$path} is empty.");
        }

        // Stored as base64url of the raw key bytes (see jwt:keys).
        return $contents;
    }
}
