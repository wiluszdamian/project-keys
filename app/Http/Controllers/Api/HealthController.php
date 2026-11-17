<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Redis;
use Throwable;

class HealthController extends Controller
{
    /**
     * Sprawdza, czy działa baza i Redis. Zwraca 503, jeśli coś nie działa.
     */
    public function __invoke(): JsonResponse
    {
        $checks = [
            'database' => $this->check(fn () => DB::select('select 1')),
            'redis' => $this->check(fn () => Redis::connection()->ping()),
        ];

        $ok = ! in_array(false, $checks, true);

        return response()->json([
            'status' => $ok ? 'ok' : 'error',
            'checks' => $checks,
        ], $ok ? 200 : 503);
    }

    private function check(callable $callback): bool
    {
        try {
            $callback();

            return true;
        } catch (Throwable $e) {
            report($e);

            return false;
        }
    }
}
