<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\ScrapeRun;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Redis;
use Throwable;

class HealthController extends Controller
{
    /**
     * Sprawdza bazę i Redis, pokazuje kolejkę i ostatni przebieg. Zwraca 503, jeśli baza lub Redis nie działa.
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
            'queue_size' => $this->value(fn () => array_sum(array_map(fn ($queue) => Queue::size($queue), ['notify', 'ai', 'scrape', 'default']))),
            'last_scrape_at' => $checks['database'] ? $this->value(fn () => ScrapeRun::max('started_at')) : null,
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

    private function value(callable $callback): mixed
    {
        try {
            return $callback();
        } catch (Throwable) {
            return null;
        }
    }
}
