<?php

namespace App\Jobs;

use App\Enums\ScrapeRunStatus;
use App\Events\ListingMatched;
use App\Events\ListingPriceChanged;
use App\Events\PortalBlockedRepeatedly;
use App\Models\ScrapeRun;
use App\Models\Search;
use App\Scrapers\Exceptions\PortalBlockedException;
use App\Scrapers\Exceptions\ScraperException;
use App\Scrapers\HttpFetcher;
use App\Scrapers\ScraperRegistry;
use App\Services\ListingMatcher;
use App\Services\ListingUpserter;
use Illuminate\Bus\Batchable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Pobiera oferty jednego portalu dla jednego wyszukiwania i zapisuje wynik w scrape_runs.
 */
class ScrapePortalJob implements ShouldQueue
{
    use Batchable, Queueable;

    // Po tylu blokadach z rzędu idzie alert.
    public const BLOCKS_BEFORE_ALERT = 3;

    public int $timeout = 600;

    // Wiele prób tylko na czekanie na wolny portal. Błąd w kodzie kończy job od razu.
    public int $tries = 30;

    public int $maxExceptions = 1;

    public function __construct(
        public Search $search,
        public string $portal,
        public bool $duringFirstRun = false,
    ) {
        $this->onQueue('scrape');
    }

    /**
     * Jeden job naraz na portal, nawet dla różnych wyszukiwań.
     */
    public function middleware(): array
    {
        return [(new WithoutOverlapping("portal:{$this->portal}"))->releaseAfter(30)->expireAfter(900)];
    }

    public function handle(ScraperRegistry $registry, HttpFetcher $fetcher, ListingMatcher $matcher, ListingUpserter $upserter): void
    {
        if ($this->batch()?->cancelled()) {
            return;
        }

        if ($fetcher->isPaused($this->portal)) {
            Log::info("Pomijam {$this->portal} dla wyszukiwania #{$this->search->id}: portal wstrzymany po blokadzie.");

            return;
        }

        $run = ScrapeRun::create([
            'search_id' => $this->search->id,
            'portal' => $this->portal,
            'started_at' => now(),
            'status' => ScrapeRunStatus::Running,
        ]);

        try {
            $result = $registry->get($this->portal)->fetchListings($this->search);
        } catch (PortalBlockedException $e) {
            $this->finish($run, ScrapeRunStatus::Blocked, $e->getMessage());
            $this->alertIfBlockedRepeatedly($e->getMessage());

            return;
        } catch (ScraperException $e) {
            $this->finish($run, ScrapeRunStatus::Error, $e->getMessage());
            Log::warning("Błąd scrapera {$this->portal}: {$e->getMessage()}");

            return;
        } catch (Throwable $e) {
            $this->finish($run, ScrapeRunStatus::Error, $e->getMessage());

            throw $e;
        }

        $new = 0;

        foreach ($result->listings as $data) {
            if (! $matcher->matches($this->search, $data)) {
                continue;
            }

            $saved = $upserter->upsert($data);

            if ($upserter->attach($this->search, $saved->listing)) {
                $new++;

                // Ta sama oferta z innego portalu: bez drugiego powiadomienia.
                if ($saved->listing->duplicate_of_id !== null) {
                    DB::table('search_listing')
                        ->where('search_id', $this->search->id)
                        ->where('listing_id', $saved->listing->id)
                        ->update(['notify_skipped_reason' => 'duplicate']);
                }

                ListingMatched::dispatch($this->search, $saved->listing, $this->duringFirstRun);
            } else {
                if ($saved->priceChanged) {
                    ListingPriceChanged::dispatch($this->search, $saved->listing, $saved->oldPrice, $saved->listing->price);
                }

                // Zmieniona treść już ocenionej oferty: nowa ocena (bez nowego powiadomienia).
                if ($saved->contentChanged && $saved->listing->analyses()->where('search_id', $this->search->id)->exists()) {
                    AnalyzeListingJob::dispatch($this->search, $saved->listing);
                }
            }
        }

        $this->finish($run, ScrapeRunStatus::Ok, pages: $result->pagesFetched, found: count($result->listings), new: $new);
    }

    private function finish(ScrapeRun $run, ScrapeRunStatus $status, ?string $error = null, int $pages = 0, int $found = 0, int $new = 0): void
    {
        $run->update([
            'status' => $status,
            'finished_at' => now(),
            'pages_fetched' => $pages,
            'found_count' => $found,
            'new_count' => $new,
            'error_message' => $error,
        ]);
    }

    private function alertIfBlockedRepeatedly(string $message): void
    {
        $lastStatuses = ScrapeRun::query()
            ->where('portal', $this->portal)
            ->where('status', '!=', ScrapeRunStatus::Running)
            ->latest('started_at')
            ->latest('id')
            ->limit(self::BLOCKS_BEFORE_ALERT)
            ->pluck('status');

        $allBlocked = $lastStatuses->count() === self::BLOCKS_BEFORE_ALERT
            && $lastStatuses->every(fn (ScrapeRunStatus $status) => $status === ScrapeRunStatus::Blocked);

        if ($allBlocked) {
            Log::warning("Portal {$this->portal} zablokował nas {$lastStatuses->count()} razy z rzędu.");
            PortalBlockedRepeatedly::dispatch($this->portal, $lastStatuses->count(), $message);
        }
    }
}
