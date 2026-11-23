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
        // Plik zaczyna się od BOM.
        $document = simplexml_load_string(preg_replace('/^\xEF\xBB\xBF/', '', trim($xml)), options: LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING);
        $node = $document ? ($document->xpath('//pozycja[@id="ref"]')[0] ?? null) : null;

        if ($node === null || ! is_numeric(str_replace(',', '.', (string) $node['oprocentowanie']))) {
            return null;
        }

        return ['rate' => (float) str_replace(',', '.', (string) $node['oprocentowanie']), 'since' => (string) $node['obowiazuje_od']];
    }
}
