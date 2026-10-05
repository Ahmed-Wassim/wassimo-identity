<?php

namespace App\Services;

use App\Models\User;
use App\Support\UserResource;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Laravel\Sanctum\PersonalAccessToken;

class AuthService
{
    // A REAL bcrypt hash, generated once per process. Hash::check against a
    // fake string throws ("does not use the Bcrypt algorithm") — which would
    // turn the unknown-email path into a 500, leaking account existence via
    // status code AND breaking the identical-timing rule. ADR-013 v2.
    private static ?string $dummyHash = null;

    private static function dummyHash(): string
    {
        return self::$dummyHash ??= Hash::make(Str::random(32));
    }

    public function register(array $data): User
    {
        return User::create($data);
    }

    public function attemptLogin(string $email, string $password): ?User
    {
        $user = User::where('email', $email)->first();

        $passwordOk = $user
            ? Hash::check($password, $user->password)
            : Hash::check($password, self::dummyHash());

        if (! $user || ! $passwordOk) {
            return null;
        }

        return $user;
    }

    public function issueTokenPair(User $user, string $deviceName): array
    {
        $accessExpiry = now()->addMinutes((int) config('sanctum.access_token_expiration', 15));
        $refreshExpiry = now()->addMinutes((int) config('sanctum.refresh_token_expiration', 43200));

        $access = $user->createToken($deviceName, ['*'], $accessExpiry);
        $refresh = $user->createToken($deviceName.':refresh', ['refresh'], $refreshExpiry);

        return [
            'access_token' => $access->plainTextToken,
            'refresh_token' => $refresh->plainTextToken,
            'token_type' => 'Bearer',
            'access_token_expires_at' => $accessExpiry->toIso8601String(),
            'refresh_token_expires_at' => $refreshExpiry->toIso8601String(),
        ];
    }

    public function refresh(string $refreshToken): array
    {
        $parts = explode('|', $refreshToken, 2);
        if (count($parts) !== 2) {
            abort(401, 'invalid refresh token');
        }

        [$id, $plain] = $parts;

        /** @var PersonalAccessToken|null $tokenModel */
        $tokenModel = PersonalAccessToken::find((int) $id);

        if (
            ! $tokenModel
            || ! hash_equals($tokenModel->token, hash('sha256', $plain))
            || ! $tokenModel->can('refresh')
            || ($tokenModel->expires_at && $tokenModel->expires_at->isPast())
        ) {
            abort(401, 'invalid refresh token');
        }

        /** @var User $user */
        $user = $tokenModel->tokenable;
        $deviceName = str_replace(':refresh', '', $tokenModel->name);

        // Revoke only this device session; other devices stay alive.
        $user->tokens()
            ->whereIn('name', [$deviceName, $deviceName.':refresh'])
            ->delete();

        $tokens = $this->issueTokenPair($user, $deviceName);
        $tokens['user'] = UserResource::make($user);

        return $tokens;
    }

    public function logout(User $user): void
    {
        $deviceName = str_replace(':refresh', '', $user->currentAccessToken()->name);

        $user->tokens()
            ->whereIn('name', [$deviceName, $deviceName.':refresh'])
            ->delete();
    }

    public function logoutAll(User $user): void
    {
        $user->tokens()->delete();
    }

    public function changePassword(User $user, string $currentPassword, string $newPassword): bool
    {
        if (! Hash::check($currentPassword, $user->password)) {
            return false;
        }

        $user->update(['password' => $newPassword]);

        $deviceName = str_replace(':refresh', '', $user->currentAccessToken()->name);

        // Keep caller's device pair, revoke everything else.
        $user->tokens()
            ->whereNotIn('name', [$deviceName, $deviceName.':refresh'])
            ->delete();

        return true;
    }

    public function revokeToken(User $user, int $id): bool
    {
        $token = $user->tokens()->find($id);

        if (! $token) {
            return false;
        }

        $deviceName = str_replace(':refresh', '', $token->name);

        $user->tokens()
            ->whereIn('name', [$deviceName, $deviceName.':refresh'])
            ->delete();

        return true;
    }
}
