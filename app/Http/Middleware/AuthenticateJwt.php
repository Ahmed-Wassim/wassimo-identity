<?php

namespace App\Http\Middleware;

use App\Models\User;
use App\Services\JwtService;
use Closure;
use Illuminate\Http\Request;

/**
 * Bearer access JWTs issued by JwtService. Replaces auth:api (Sanctum) on
 * access-token routes — Sanctum rows now back refresh tokens only.
 */
class AuthenticateJwt
{
    public function __construct(private JwtService $jwt) {}

    public function handle(Request $request, Closure $next)
    {
        $token = $request->bearerToken();

        if (! $token) {
            return response()->json(['error' => 'unauthenticated'], 401);
        }

        try {
            $claims = $this->jwt->verify($token);
        } catch (\Throwable) {
            // One identical 401 for expired, tampered, wrong-kid and
            // wrong-issuer tokens — never leak which check failed.
            return response()->json(['error' => 'unauthenticated'], 401);
        }

        $user = User::find($claims->sub ?? null);

        if (! $user) {
            return response()->json(['error' => 'unauthenticated'], 401);
        }

        $request->setUserResolver(fn () => $user);
        $request->attributes->set('jwt_claims', (array) $claims);

        return $next($request);
    }
}
