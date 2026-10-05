<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Services\AuthService;
use App\Support\UserResource;
use Illuminate\Database\QueryException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AuthController extends Controller
{
    public function __construct(private AuthService $auth) {}

    public function register(Request $request): JsonResponse
    {
        // No `unique` rule here on purpose: it fails as 422, but the contract
        // (ADR-013 v2) says duplicate registration is 409.
        $data = $request->validate([
            'email' => ['required', 'email', 'max:191'],
            'password' => ['required', 'string', 'min:8', 'max:72'],
        ]);

        if (User::where('email', $data['email'])->exists()) {
            return response()->json(['error' => 'email already registered'], 409);
        }

        try {
            $user = $this->auth->register($data);
        } catch (QueryException $e) {
            // Lost a race with another registration — same answer, no leak.
            if ((string) $e->getCode() === '23000') {
                return response()->json(['error' => 'email already registered'], 409);
            }
            throw $e;
        }

        return response()->json(['user' => UserResource::make($user)], 201);
    }

    public function login(Request $request): JsonResponse
    {
        $request->validate([
            'email' => ['required', 'email'],
            'password' => ['required', 'string', 'max:72'],
            'device_name' => ['nullable', 'string', 'max:64'],
        ]);

        $user = $this->auth->attemptLogin($request->email, $request->password);

        if (! $user) {
            return response()->json(['error' => 'invalid credentials'], 401);
        }

        $tokens = $this->auth->issueTokenPair($user, $request->input('device_name', 'api'));

        return response()->json(array_merge($tokens, [
            'user' => UserResource::make($user),
        ]));
    }

    public function refresh(Request $request): JsonResponse
    {
        $request->validate([
            'refresh_token' => ['required', 'string'],
        ]);

        $tokens = $this->auth->refresh($request->input('refresh_token'));

        return response()->json($tokens);
    }

    public function me(Request $request): JsonResponse
    {
        return response()->json(['user' => UserResource::make($request->user('api'))]);
    }

    public function logout(Request $request): JsonResponse
    {
        $this->auth->logout($request->user('api'));

        return response()->json(null, 204);
    }

    public function logoutAll(Request $request): JsonResponse
    {
        $this->auth->logoutAll($request->user('api'));

        return response()->json(null, 204);
    }

    public function tokens(Request $request): JsonResponse
    {
        $user = $request->user('api');
        $current = $user->currentAccessToken()->id;

        $tokens = $user->tokens()
            ->where('abilities', 'not like', '%refresh%')
            ->orderByDesc('created_at')
            ->get()
            ->map(fn ($t) => [
                'id' => $t->id,
                'name' => $t->name,
                'last_used_at' => $t->last_used_at?->toIso8601String(),
                'expires_at' => $t->expires_at?->toIso8601String(),
                'created_at' => $t->created_at->toIso8601String(),
                'current' => $t->id === $current,
            ]);

        return response()->json(['tokens' => $tokens]);
    }

    public function deleteToken(Request $request, int $id): JsonResponse
    {
        if (! $this->auth->revokeToken($request->user('api'), $id)) {
            return response()->json(['error' => 'forbidden'], 403);
        }

        return response()->json(null, 204);
    }

    public function changePassword(Request $request): JsonResponse
    {
        $request->validate([
            'current_password' => ['required', 'string'],
            'password' => ['required', 'string', 'min:8', 'max:72'],
        ]);

        $ok = $this->auth->changePassword(
            $request->user('api'),
            $request->current_password,
            $request->password,
        );

        if (! $ok) {
            return response()->json(['error' => 'current password is incorrect'], 422);
        }

        return response()->json(null, 204);
    }
}
