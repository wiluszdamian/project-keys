<?php

namespace App\Services\Geo;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Czasy przejazdu z naszego serwera OSRM (auto i rower). Nic nie wychodzi poza serwer.
 */
class Router
{
    /**
     * Macierz czasów i odległości: dla każdego punktu startu do każdego celu.
     *
     * @param  list<array{0: float, 1: float}>  $sources  [lat, lng]
     * @param  list<array{0: float, 1: float}>  $destinations  [lat, lng]
     * @return list<list<array{minutes: int, km: float, meters: int}|null>>|null null, gdy OSRM nie działa
     */
    public function table(string $mode, array $sources, array $destinations): ?array
    {
        if ($sources === [] || $destinations === []) {
            return [];
        }

        $points = collect([...$sources, ...$destinations])->map(fn (array $point) => sprintf('%.6F,%.6F', $point[1], $point[0]))->implode(';');
        $count = count($sources);

        try {
            $response = Http::timeout(30)->get(config("geo.osrm.{$mode}")."/table/v1/{$mode}/{$points}", [
                'sources' => implode(';', range(0, $count - 1)),
                'destinations' => implode(';', range($count, $count + count($destinations) - 1)),
                'annotations' => 'duration,distance',
            ]);
        } catch (Throwable $exception) {
            Log::warning("OSRM {$mode}: brak odpowiedzi", ['error' => $exception->getMessage()]);

            return null;
        }

        if (! $response->successful() || $response->json('code') !== 'Ok') {
            Log::warning("OSRM {$mode}: błąd", ['status' => $response->status(), 'body' => mb_substr($response->body(), 0, 200)]);

            return null;
        }

        $durations = $response->json('durations');
        $distances = $response->json('distances');

        return array_map(fn (int $i) => array_map(
            fn (int $j) => isset($durations[$i][$j], $distances[$i][$j])
                ? ['minutes' => (int) max(1, round($durations[$i][$j] / 60)), 'km' => round($distances[$i][$j] / 1000, 1), 'meters' => (int) round($distances[$i][$j])]
                : null,
            array_keys($destinations),
        ), array_keys($sources));
    }
}
