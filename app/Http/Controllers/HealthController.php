<?php

namespace App\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

class HealthController extends Controller
{
    public function health(): JsonResponse
    {
        return response()->json(['status' => 'ok']);
    }

    public function ready(): JsonResponse
    {
        try {
            DB::connection()->getPdo();
            Cache::store('redis')->put('_ready_probe', 1, 5);
        } catch (\Throwable $e) {
            return response()->json(['status' => 'unavailable', 'error' => $e->getMessage()], 503);
        }

        return response()->json(['status' => 'ok']);
    }
}
