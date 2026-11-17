<?php

namespace App\Jobs;

use App\Models\Listing;
use App\Models\Search;
use App\Notifications\Notifier;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\DB;

/**
 * Jedno podsumowanie: liczba nowych ofert i 10 najlepszych (po ocenie AI, a bez oceny najnowsze).
 */
class SendDigestJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 5;

    public array $backoff = [30, 120, 600, 1800];

    /**
     * @param  list<int>  $listingIds
     */
    public function __construct(public Search $search, public array $listingIds, public bool $firstRun)
    {
        $this->onQueue('notify');
    }

    public function handle(Notifier $notifier): void
    {
        $minScore = $this->search->min_score_to_notify;

        // Oferty od progu albo bez oceny (błąd AI). Słabsze tylko w liczniku.
        $top = Listing::query()
            ->select('listings.*', 'listing_analyses.score', 'listing_analyses.monthly_cost')
            ->leftJoin('listing_analyses', function ($join) {
                $join->on('listing_analyses.listing_id', '=', 'listings.id')
                    ->where('listing_analyses.search_id', $this->search->id);
            })
            ->whereIn('listings.id', $this->listingIds)
            ->where(fn ($query) => $query->whereNull('listing_analyses.score')->orWhere('listing_analyses.score', '>=', $minScore))
            ->orderByRaw('listing_analyses.score desc nulls last')
            ->orderByDesc('listings.first_seen_at')
            ->limit(config('notify.digest_size'))
            ->get();

        $qualifying = Listing::query()
            ->leftJoin('listing_analyses', function ($join) {
                $join->on('listing_analyses.listing_id', '=', 'listings.id')
                    ->where('listing_analyses.search_id', $this->search->id);
            })
            ->whereIn('listings.id', $this->listingIds)
            ->where(fn ($query) => $query->whereNull('listing_analyses.score')->orWhere('listing_analyses.score', '>=', $minScore))
            ->count();

        $notifier->digest($this->search, $top, count($this->listingIds), $this->firstRun, $qualifying);

        $rows = DB::table('search_listing')->where('search_id', $this->search->id);
        (clone $rows)->whereIn('listing_id', $top->modelKeys())->update(['notified_at' => now()]);
        (clone $rows)->whereIn('listing_id', array_diff($this->listingIds, $top->modelKeys()))
            ->whereNull('notified_at')
            ->update(['notify_skipped_reason' => 'digest']);
    }
}
