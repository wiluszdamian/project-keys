<?php

namespace App\Jobs;

use App\Ai\InvalidAiResponseException;
use App\Models\Listing;
use App\Models\Search;
use App\Services\ListingScorer;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\Middleware\RateLimited;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Ocena AI jednej oferty dla jednego wyszukiwania. Działa w łańcuchu przed powiadomieniem,
 * więc nigdy nie kończy się porażką: po 3 błędach zapisuje problem i puszcza łańcuch dalej.
 */
class AnalyzeListingJob implements ShouldQueue
{
    use Queueable;

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

    public function handle(ListingScorer $scorer): void
    {
        if (! ListingScorer::isEnabled()) {
            return;
        }

        $existing = $this->listing->analyses()->where('search_id', $this->search->id)->first();

        if ($existing && $existing->listing_content_hash === $this->listing->content_hash) {
            return;
        }

        try {
            $scorer->score($this->search, $this->listing);
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
}
