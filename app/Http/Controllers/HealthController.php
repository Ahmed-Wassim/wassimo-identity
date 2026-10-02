<?php

namespace App\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * Health and readiness probes.
 *
 * GET /health  — liveness: always 200 while the PHP process is alive.
 *               MUST NOT touch MySQL or Redis — a DB outage must not
 *               restart a healthy process.
 *
 * GET /ready   — readiness: 200 only when MySQL AND Redis are reachable.
 *               ADR-015: Redis is mandatory (Spatie cache + rate limiter);
 *               a Redis outage degrades login and is worth reporting as
 *               "not ready" even though the process itself is alive.
 */
class HealthController extends Controller
{
    public function health(): JsonResponse
    {
        return response()->json(['status' => 'ok']);
    }

    public function ready(): JsonResponse
    {
        try {
            // MySQL check — getPdo() throws on connection failure
            DB::connection()->getPdo();

            // Redis check — write a short-lived probe key
            Cache::store('redis')->put('_ready_probe', 1, 5);
        } catch (\Throwable $e) {
            return response()->json(['status' => 'unavailable', 'error' => $e->getMessage()], 503);
        }

        return response()->json(['status' => 'ok']);
    }
}
