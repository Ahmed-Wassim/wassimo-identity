<?php

use App\Http\Controllers\AuthController;
use App\Http\Controllers\HealthController;
use App\Http\Controllers\UserController;
use Illuminate\Support\Facades\Route;

Route::get('/ready', [HealthController::class, 'ready']);

Route::prefix('auth')->group(function () {
    Route::post('/register', [AuthController::class, 'register']);
    Route::post('/login',    [AuthController::class, 'login']);
    Route::post('/refresh',  [AuthController::class, 'refresh']);
});

Route::prefix('auth')->middleware('auth.jwt')->group(function () {
    Route::get('/me',               [AuthController::class, 'me']);
    Route::post('/logout',          [AuthController::class, 'logout']);
    Route::post('/logout-all',      [AuthController::class, 'logoutAll']);
    Route::get('/tokens',           [AuthController::class, 'tokens']);
    Route::delete('/tokens/{id}',   [AuthController::class, 'deleteToken']);
    Route::post('/password/change', [AuthController::class, 'changePassword']);
});

Route::middleware('auth.jwt')->group(function () {
    Route::get('/roles',       [UserController::class, 'roles']);
    Route::get('/permissions', [UserController::class, 'permissions']);

    Route::prefix('users')->group(function () {
        Route::get('/',                 [UserController::class, 'index']);
        Route::post('/',                [UserController::class, 'store']);
        Route::get('/{id}',             [UserController::class, 'show']);
        Route::delete('/{id}',          [UserController::class, 'destroy']);
        Route::put('/{id}/roles',       [UserController::class, 'syncRoles']);
        Route::put('/{id}/permissions', [UserController::class, 'syncPermissions']);
    });
});
