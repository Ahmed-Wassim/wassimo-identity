<?php

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/health',          // liveness probe — never touches DB/Redis
    )
    ->withMiddleware(function (Middleware $middleware): void {
        // Force every request through the api guard for Sanctum token lookup.
        // Without this, auth:sanctum falls back to the web (session) guard,
        // and Bearer tokens are silently ignored.
        $middleware->statefulApi();
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        // Always return JSON for API paths and requests that expect JSON.
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );

        // Render auth exceptions as JSON 401 instead of a redirect.
        $exceptions->render(function (\Illuminate\Auth\AuthenticationException $e, Request $request) {
            return response()->json(['error' => 'unauthenticated'], 401);
        });
    })->create();
