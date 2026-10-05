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
        // No 'api' prefix: the frozen contract (ADR-013 v2) addresses this
        // service at /auth/*, /users/*, /ready — the gateway proxies keep-path,
        // so a prefix here would 404 every gatewayed call.
        apiPrefix: '',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->alias(['auth.jwt' => \App\Http\Middleware\AuthenticateJwt::class]);

        // API-only service: never redirect a guest to a login page. The
        // framework default calls route('login'), which does not exist here
        // and turns a 401 into a 500 RouteNotFoundException for any caller
        // that omits Accept: application/json. Null forces the JSON 401 below.
        $middleware->redirectGuestsTo(fn () => null);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        // Always return JSON for API paths and requests that expect JSON.
        // Always-JSON (not path-scoped): this service has no browser flows —
        // Fortify views are disabled, auth is bearer-token only — so an HTML
        // error page would only ever leak through the gateway as a mystery
        // body. The gateway forwards Accept, but never rely on it arriving.
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => true,
        );

        // Render auth exceptions as JSON 401 instead of a redirect.
        $exceptions->render(function (\Illuminate\Auth\AuthenticationException $e, Request $request) {
            return response()->json(['error' => 'unauthenticated'], 401);
        });
    })->create();
