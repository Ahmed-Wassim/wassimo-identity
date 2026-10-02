<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Support\UserResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rules\Password;

/**
 * Authentication controller — ADR-013 v2.
 *
 * Design rules that every method here must follow:
 *
 *  ENUMERATION: register() returns 409 for duplicate email (registration is
 *    public; that leak is acceptable). login() returns the SAME status + body
 *    AND runs a timing-equalising hash on the "no account" path so response
 *    time does not reveal whether the email exists.
 *
 *  GUARD: all Sanctum calls use the "api" guard explicitly. Relying on the
 *    default guard is fragile — the default is whatever config/auth.php says,
 *    and that can change.
 *
 *  TOKEN SHAPE: every response that includes a token returns:
 *    { token, token_type, expires_at, user: UserResource }
 *    expires_at is ISO-8601 UTC, derived from sanctum.expiration.
 *
 *  PASSWORD: max 72 bytes enforced to surface bcrypt's silent truncation.
 *    Hash::check() is the only path that ever touches a password — never
 *    compare plaintext.
 */
class AuthController extends Controller
{
    // Precomputed dummy hash used in the timing-equalising login path.
    // Computed once at class load, not per-request, so the cost is constant.
    private static string $dummyHash = '$2y$12$invalidhashpadding000000000000000000000000000000000000000';

    // ── POST /auth/register ───────────────────────────────────────────────

    /**
     * Create a new account. Returns 201 with the user shape (no token).
     * Clients must call /auth/login separately — register is not a login.
     *
     * Returns 409 if the email is already registered. Enumeration via
     * register is acceptable (the email has to exist to register).
     */
    public function register(Request $request): JsonResponse
    {
        $data = $request->validate([
            'email'    => ['required', 'email', 'max:191', 'unique:users,email'],
            'password' => ['required', 'string', 'min:8', 'max:72'],
        ]);

        $user = User::create($data);

        return response()->json(['user' => UserResource::make($user)], 201);
    }

    // ── POST /auth/login ──────────────────────────────────────────────────

    /**
     * Authenticate and issue a token.
     *
     * ENUMERATION RULE: whether the email does not exist OR the password is
     * wrong, we return an identical 401 body. The "no account" path still
     * runs Hash::check() against a dummy hash so response timing is the same.
     *
     * device_name: optional label stored on the token row. Used in GET
     * /auth/tokens to name sessions. Defaults to "api" when absent.
     */
    public function login(Request $request): JsonResponse
    {
        $request->validate([
            'email'       => ['required', 'email'],
            'password'    => ['required', 'string', 'max:72'],
            'device_name' => ['nullable', 'string', 'max:64'],
        ]);

        $user = User::where('email', $request->email)->first();

        // Timing-safe: always run a hash comparison, even when no user found.
        $passwordOk = $user
            ? Hash::check($request->password, $user->password)
            : Hash::check($request->password, self::$dummyHash);

        if (! $user || ! $passwordOk) {
            return response()->json(['error' => 'invalid credentials'], 401);
        }

        $deviceName = $request->input('device_name', 'api');
        $expiresAt  = now()->addMinutes((int) config('sanctum.expiration', 1440));

        $token = $user->createToken($deviceName, ['*'], $expiresAt);

        return response()->json([
            'token'      => $token->plainTextToken,
            'token_type' => 'Bearer',
            'expires_at' => $expiresAt->toIso8601String(),
            'user'       => UserResource::make($user),
        ]);
    }

    // ── GET /auth/me ──────────────────────────────────────────────────────

    /**
     * Return the authenticated user's profile + roles + permissions.
     * The gateway calls this endpoint to validate every protected request.
     */
    public function me(Request $request): JsonResponse
    {
        return response()->json(['user' => UserResource::make($request->user('api'))]);
    }

    // ── POST /auth/logout ─────────────────────────────────────────────────

    /**
     * Revoke the current token. Returns 204.
     */
    public function logout(Request $request): JsonResponse
    {
        $request->user('api')->currentAccessToken()->delete();

        return response()->json(null, 204);
    }

    // ── POST /auth/logout-all ─────────────────────────────────────────────

    /**
     * Revoke every other token but keep the current session active.
     * A user suspecting a breach can run this without locking themselves out.
     */
    public function logoutAll(Request $request): JsonResponse
    {
        $user    = $request->user('api');
        $current = $user->currentAccessToken()->id;

        $user->tokens()->where('id', '!=', $current)->delete();

        return response()->json(null, 204);
    }

    // ── GET /auth/tokens ──────────────────────────────────────────────────

    /**
     * List all active tokens for the current user.
     * Never returns the token value — only metadata.
     */
    public function tokens(Request $request): JsonResponse
    {
        $user    = $request->user('api');
        $current = $user->currentAccessToken()->id;

        $tokens = $user->tokens()
            ->orderByDesc('created_at')
            ->get()
            ->map(fn ($t) => [
                'id'           => $t->id,
                'name'         => $t->name,
                'last_used_at' => $t->last_used_at?->toIso8601String(),
                'expires_at'   => $t->expires_at?->toIso8601String(),
                'created_at'   => $t->created_at->toIso8601String(),
                'current'      => $t->id === $current,
            ]);

        return response()->json(['tokens' => $tokens]);
    }

    // ── DELETE /auth/tokens/{id} ──────────────────────────────────────────

    /**
     * Revoke a specific token by ID.
     *
     * Returns 403 (not 404) when the token belongs to a different user.
     * 404 would confirm the ID exists, which is an enumeration surface on
     * the personal_access_tokens table.
     */
    public function deleteToken(Request $request, int $id): JsonResponse
    {
        $user  = $request->user('api');
        $token = $user->tokens()->find($id);

        if (! $token) {
            // Could be another user's token or non-existent — both get 403.
            return response()->json(['error' => 'forbidden'], 403);
        }

        $token->delete();

        return response()->json(null, 204);
    }

    // ── POST /auth/password/change ────────────────────────────────────────

    /**
     * Change the password for the current user.
     *
     * Requires the current password (validated as 422 on mismatch, not 401,
     * so the client knows the token is still valid — only the old password
     * was wrong). Invalidates every other session on success.
     */
    public function changePassword(Request $request): JsonResponse
    {
        $request->validate([
            'current_password' => ['required', 'string'],
            'password'         => ['required', 'string', 'min:8', 'max:72'],
        ]);

        $user = $request->user('api');

        if (! Hash::check($request->current_password, $user->password)) {
            return response()->json(['error' => 'current password is incorrect'], 422);
        }

        $user->update(['password' => $request->password]);

        // Revoke all tokens except the current one so existing sessions on
        // other devices are forced to re-authenticate.
        $current = $user->currentAccessToken()->id;
        $user->tokens()->where('id', '!=', $current)->delete();

        return response()->json(null, 204);
    }
}
