<?php

namespace App\Jobs;

use App\Models\Search;
use App\Scrapers\ScraperRegistry;
use Illuminate\Bus\Batch;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Log;

/**
 * Jeden przebieg wyszukiwania: osobny ScrapePortalJob dla każdego portalu.
 * Po pierwszym pełnym przebiegu ustawia first_run_completed.
 */
class RunSearchJob implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    // Nie dodawaj drugiego takiego joba, dopóki ten czeka w kolejce.
    public int $uniqueFor = 900;

    public function __construct(public Search $search)
    {
        $this->onQueue('scrape');
    }

    public function uniqueId(): string
    {
        return (string) $this->search->id;
    }

    public function middleware(): array
    {
        return [(new WithoutOverlapping("search:{$this->search->id}"))->dontRelease()->expireAfter(1800)];
    }

    public function handle(ScraperRegistry $registry): void
    {
        $search = $this->search;
        // last_run_at nie jest w $fillable (API go nie zmienia), stąd forceFill.
        $search->forceFill(['last_run_at' => now()])->save();

        $portals = array_values(array_intersect($search->portals, $registry->portals()));

        if ($portals === []) {
            Log::warning("Wyszukiwanie #{$search->id} nie ma portali ze scraperem.", ['portals' => $search->portals]);

            return;
        }

        $firstRun = ! $search->first_run_completed;
        $startedAt = now()->startOfSecond()->toIso8601String();
        $jobs = array_map(fn (string $portal) => new ScrapePortalJob($search, $portal, $firstRun), $portals);
        $searchId = $search->id;

        Bus::batch($jobs)
            ->name("search:{$searchId}")
            ->onQueue('scrape')
            ->allowFailures()
            ->finally(function (Batch $batch) use ($searchId, $startedAt, $firstRun) {
                Search::whereKey($searchId)->where('first_run_completed', false)->update(['first_run_completed' => true]);

                // Powiadomienia po zakończeniu wszystkich portali: jedno podsumowanie albo pojedyncze wiadomości.
                if ($search = Search::find($searchId)) {
                    NotifyRunResultsJob::dispatch($search, $startedAt, $firstRun);
                }
            })
            ->dispatch();
    }
}
