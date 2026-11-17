<?php

namespace App\Scrapers;

use App\Scrapers\Exceptions\PortalBlockedException;
use App\Scrapers\Exceptions\ScraperException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Sleep;

/**
 * Wspólny klient HTTP dla scraperów. Pilnuje przerw między zapytaniami,
 * jednego zapytania naraz na portal, ponowień i wstrzymania portalu po blokadzie.
 */
class HttpFetcher
{
    public function get(string $portal, string $url, array $headers = []): string
    {
        $this->ensureNotPaused($portal);

        // Jedno zapytanie naraz do danego portalu, także z kilku workerów.
        return Cache::lock("scraper:lock:{$portal}", 300)->block(600, function () use ($portal, $url, $headers) {
            $this->waitForTurn($portal);

            try {
                return $this->request($portal, $url, $headers);
            } finally {
                Cache::put("scraper:last:{$portal}", microtime(true), now()->addHour());
            }
        });
    }

    public function isPaused(string $portal): bool
    {
        return Cache::has("scraper:paused:{$portal}");
    }

    public function pause(string $portal): void
    {
        $hours = config('scrapers.block_hours');
        Cache::put("scraper:paused:{$portal}", now()->addHours($hours)->toIso8601String(), now()->addHours($hours));
    }

    private function ensureNotPaused(string $portal): void
    {
        if ($this->isPaused($portal)) {
            $until = Cache::get("scraper:paused:{$portal}");

            throw new PortalBlockedException("Portal {$portal} jest wstrzymany do {$until} po blokadzie.");
        }
    }

    private function waitForTurn(string $portal): void
    {
        $last = Cache::get("scraper:last:{$portal}");

        if ($last === null) {
            return;
        }

        $delayMs = config('scrapers.delay_ms') + random_int(0, config('scrapers.jitter_ms'));
        $elapsedMs = (int) ((microtime(true) - $last) * 1000);

        if ($elapsedMs < $delayMs) {
            Sleep::for($delayMs - $elapsedMs)->milliseconds();
        }
    }

    private function request(string $portal, string $url, array $headers): string
    {
        $retries = config('scrapers.retries');

        for ($attempt = 0; ; $attempt++) {
            try {
                $response = Http::withHeaders(array_merge([
                    'User-Agent' => config('scrapers.user_agent'),
                    'Accept' => 'text/html,application/xhtml+xml,application/xml;q=0.9,*/*;q=0.8',
                    'Accept-Language' => 'pl-PL,pl;q=0.9,en;q=0.8',
                ], $headers))
                    // HTTP/2 jak przeglądarka. OLX (CloudFront) odrzuca HTTP/1.1 z nagłówkiem przeglądarki.
                    // Decyzja właściciela z 2026-11-17, patrz .planning/STATUS.md.
                    ->withOptions(['version' => 2.0])
                    ->timeout(config('scrapers.timeout_seconds'))
                    ->get($url);
            } catch (ConnectionException $e) {
                if ($attempt < $retries) {
                    Sleep::for(config('scrapers.retry_sleep_seconds'))->seconds();

                    continue;
                }

                throw new ScraperException("Brak odpowiedzi z {$portal}: {$e->getMessage()}", previous: $e);
            }

            if (in_array($response->status(), [403, 429], true)) {
                $this->pause($portal);

                throw new PortalBlockedException("Portal {$portal} odpowiedział {$response->status()}. Wstrzymany na ".config('scrapers.block_hours').' h.');
            }

            if ($response->serverError() && $attempt < $retries) {
                Sleep::for(config('scrapers.retry_sleep_seconds'))->seconds();

                continue;
            }

            if (! $response->successful()) {
                throw new ScraperException("Portal {$portal} odpowiedział {$response->status()} dla {$url}");
            }

            return $response->body();
        }
    }
}
