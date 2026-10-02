<?php

use App\Http\Controllers\AuthController;
use App\Http\Controllers\HealthController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| API Routes — wassimo-identity
|--------------------------------------------------------------------------
|
| Public/protected split follows ADR-013 v2.
| All routes use the "api" guard (Sanctum bearer token).
| No global auth middleware — public routes must keep working when
| identity is the service under load (e.g. GET /health must never
| require a token).
|
*/

// ── Probes ────────────────────────────────────────────────────────────────
// /health is registered via bootstrap/app.php health: '/health' (liveness).
// /ready is here because it touches MySQL + Redis and must be a real route.
Route::get('/ready', [HealthController::class, 'ready']);

// ── Public auth routes ────────────────────────────────────────────────────
Route::prefix('auth')->group(function () {
    Route::post('/register', [AuthController::class, 'register']);
    Route::post('/login',    [AuthController::class, 'login']);
});

// ── Protected auth routes ─────────────────────────────────────────────────
Route::prefix('auth')->middleware('auth:api')->group(function () {
    Route::get( '/me',              [AuthController::class, 'me']);
    Route::post('/logout',          [AuthController::class, 'logout']);
    Route::post('/logout-all',      [AuthController::class, 'logoutAll']);
    Route::get( '/tokens',          [AuthController::class, 'tokens']);
    Route::delete('/tokens/{id}',   [AuthController::class, 'deleteToken']);
    Route::post('/password/change', [AuthController::class, 'changePassword']);
});
