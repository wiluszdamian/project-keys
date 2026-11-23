<?php

namespace App\Services\Mortgage;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Stopa referencyjna NBP z oficjalnego pliku XML (static.nbp.pl). Pamiętana 12 godzin.
 */
class NbpRates
{
    private const CACHE_KEY = 'mortgage:nbp-reference-rate';

    /**
     * @return array{rate: float, since: string}|null
     */
    public function reference(): ?array
    {
        $cached = Cache::get(self::CACHE_KEY);

        if ($cached !== null) {
            return is_array($cached) ? $cached : null;
        }

        try {
            $xml = Http::timeout(10)->get(config('mortgage.nbp_rates_url'))->throw()->body();
            $result = self::parse($xml);
        } catch (Throwable $e) {
            Log::info("Nie pobrano stopy NBP: {$e->getMessage()}");
            $result = null;
        }

        // Błąd pamiętamy krócej, żeby nie pytać NBP przy każdym odświeżeniu strony.
        Cache::put(self::CACHE_KEY, $result ?? false, $result ? now()->addHours(12) : now()->addMinutes(30));

        return $result;
    }

    /**
     * @return array{rate: float, since: string}|null
     */
    public static function parse(string $xml): ?array
    {
        if (! preg_match('/<pozycja\s+id="ref"\s+oprocentowanie="([\d,.]+)"\s+obowiazuje_od="([\d-]+)"/', $xml, $match)) {
            return null;
        }

        return ['rate' => (float) str_replace(',', '.', $match[1]), 'since' => $match[2]];
    }
}
