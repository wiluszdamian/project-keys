<?php

namespace App\Jobs;

use App\Ai\InvalidAiResponseException;
use App\Models\Listing;
use App\Models\Search;
use App\Scrapers\Contracts\EnrichesListings;
use App\Scrapers\Exceptions\PortalBlockedException;
use App\Scrapers\Exceptions\ScraperException;
use App\Scrapers\ScraperRegistry;
use App\Services\ListingExtras;
use App\Services\ListingFeatures;
use App\Services\ListingMatcher;
use App\Services\ListingScorer;
use App\Services\ListingUpserter;
use Illuminate\Bus\Batchable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\Middleware\RateLimited;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Ocena AI jednej oferty dla jednego wyszukiwania, w batchu przed powiadomieniem.
 * Po 3 błędach zapisuje problem i kończy się bez wyjątku, a oferta idzie bez oceny.
 */
class AnalyzeListingJob implements ShouldQueue
{
    use Batchable, Queueable;

    private const MAX_ERRORS = 3;

    public int $timeout = 150;

    // Dużo prób, bo limit 20 na minutę odkłada job z powrotem do kolejki.
    public int $tries = 50;

    public array $backoff = [10, 60, 300];

    public function __construct(public Search $search, public Listing $listing)
    {
        $this->onQueue('ai');
    }

    public function middleware(): array
    {
        return [new RateLimited('ai')];
    }

    public function handle(ListingScorer $scorer, ScraperRegistry $registry, ListingExtras $extras, ListingMatcher $matcher, ListingUpserter $upserter): void
    {
        if (! ListingScorer::isEnabled()) {
            return;
        }

        $this->enrich($registry);

        // Pełny opis i dane ze strony ogłoszenia mogą pokazać, że oferta nie pasuje (rynek pierwotny, bez mebli, wizualizacje).
        if (! $matcher->featuresMatch($this->search, ListingFeatures::of($this->listing))) {
            $upserter->detach($this->search, $this->listing);
            Log::info("Oferta #{$this->listing->id} nie pasuje do wyszukiwania #{$this->search->id} po pobraniu strony ogłoszenia, odłączona.");

            return;
        }

        $extras->prepare($this->listing);

        $existing = $this->listing->analyses()->where('search_id', $this->search->id)->first();

        if ($existing && $existing->listing_content_hash === $this->listing->content_hash) {
            return;
        }

        try {
            $scorer->score($this->search, $this->listing->fresh());
        } catch (InvalidAiResponseException $e) {
            // Dwie złe odpowiedzi z rzędu: oferta pójdzie bez oceny.
            Log::warning($e->getMessage(), ['listing' => $this->listing->id]);
        } catch (Throwable $e) {
            $key = "ai:job-errors:{$this->search->id}:{$this->listing->id}";
            Cache::add($key, 0, now()->addDay());

            if (Cache::increment($key) < self::MAX_ERRORS) {
                throw $e;
            }

            $scorer->countError();
            Log::error("Ocena AI nie udała się 3 razy, oferta #{$this->listing->id} pójdzie bez oceny: {$e->getMessage()}");
        }
    }

    /**
     * Lista wyników nie ma wszystkich danych (opis, czynsz, współrzędne): raz pobieramy stronę ogłoszenia.
     * Znacznik raw.enriched_at pilnuje, żeby nie pobierać jej drugi raz. Błąd nie blokuje oceny.
     */
    private function enrich(ScraperRegistry $registry): void
    {
        if (isset($this->listing->raw['enriched_at']) || ! in_array($this->listing->portal, $registry->portals(), true)) {
            return;
        }

        $scraper = $registry->get($this->listing->portal);

        if (! $scraper instanceof EnrichesListings) {
            return;
        }

        try {
            $scraper->enrich($this->listing);
            $this->listing->forceFill(['raw' => array_merge($this->listing->raw ?? [], ['enriched_at' => now()->toIso8601String()])])->save();
        } catch (PortalBlockedException|ScraperException $e) {
            Log::info("Nie pobrano strony oferty #{$this->listing->id}, ocena bez opisu: {$e->getMessage()}");
        }
    }
}
