<?php

namespace App\Services\Geo;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Sleep;
use Throwable;

/**
 * Współrzędne adresu z OpenStreetMap (Nominatim). Zasady serwisu: najwyżej 1 zapytanie na sekundę,
 * własny User-Agent, wyniki trzymamy w cache, żeby nie pytać drugi raz o to samo.
 */
class Geocoder
{
    /**
     * @return array{lat: float, lng: float, label: string}|null
     */
    public function geocode(string $query): ?array
    {
        $query = trim(preg_replace('/\s+/u', ' ', $query));

        if ($query === '') {
            return null;
        }

        $key = 'geocode:'.sha1(mb_strtolower($query));
        $cached = Cache::get($key);

        if ($cached !== null) {
            return $cached ?: null;
        }

        $result = $this->request($query);
        $simple = self::simplify($query);

        // Nominatim nie lubi "ul." i kodów pocztowych: druga próba bez nich.
        if ($result === null && $simple !== $query) {
            $result = $this->request($simple);
        }

        // Brak wyniku też zapamiętujemy (pusta tablica), krócej.
        Cache::put($key, $result ?? [], $result ? now()->addDays(180) : now()->addDays(7));

        return $result;
    }

    /**
     * "ul. Rejtana 36, 35-310 Rzeszów" -> "Rejtana 36, Rzeszów".
     */
    public static function simplify(string $query): string
    {
        $query = preg_replace(['/\b\d{2}-\d{3}\b/u', '/\b(ul|al|os|pl)\.\s*/iu'], '', $query);

        return trim(preg_replace(['/\s*,\s*(,\s*)+/', '/\s+/u'], [', ', ' '], $query), ' ,');
    }

    private function request(string $query): ?array
    {
        return Cache::lock('geocode:nominatim', 30)->block(60, function () use ($query) {
            $last = (float) Cache::get('geocode:last', 0);
            $wait = 1.1 - (microtime(true) - $last);

            if ($wait > 0) {
                Sleep::for((int) ceil($wait * 1000))->milliseconds();
            }

            try {
                $response = Http::withHeaders(['User-Agent' => config('geo.nominatim.user_agent'), 'Accept-Language' => 'pl'])
                    ->timeout(20)
                    ->get(config('geo.nominatim.url').'/search', [
                        'q' => $query,
                        'format' => 'jsonv2',
                        'limit' => 1,
                        'countrycodes' => 'pl',
                    ]);
            } catch (Throwable $exception) {
                Log::warning('Geokodowanie: brak odpowiedzi', ['error' => $exception->getMessage()]);

                return null;
            } finally {
                Cache::forever('geocode:last', microtime(true));
            }

            $first = $response->successful() ? ($response->json()[0] ?? null) : null;

            return $first ? ['lat' => (float) $first['lat'], 'lng' => (float) $first['lon'], 'label' => (string) ($first['display_name'] ?? $query)] : null;
        });
    }
}
