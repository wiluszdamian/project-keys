<?php

namespace App\Jobs;

use App\Models\Listing;
use App\Models\Search;
use App\Notifications\Notifier;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\DB;

/**
 * Jedno podsumowanie: liczba nowych ofert i najlepsze z nich (po ocenie AI, a bez oceny najnowsze).
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
        $top = Listing::query()
            ->select('listings.*', 'listing_analyses.score')
            ->leftJoin('listing_analyses', function ($join) {
                $join->on('listing_analyses.listing_id', '=', 'listings.id')
                    ->where('listing_analyses.search_id', $this->search->id);
            })
            ->whereIn('listings.id', $this->listingIds)
            ->orderByRaw('listing_analyses.score desc nulls last')
            ->orderByDesc('listings.first_seen_at')
            ->limit(config('notify.digest_size'))
            ->get();

        $notifier->digest($this->search, $top, count($this->listingIds), $this->firstRun);

        $rows = DB::table('search_listing')->where('search_id', $this->search->id);
        (clone $rows)->whereIn('listing_id', $top->modelKeys())->update(['notified_at' => now()]);
        (clone $rows)->whereIn('listing_id', array_diff($this->listingIds, $top->modelKeys()))
            ->whereNull('notified_at')
            ->update(['notify_skipped_reason' => 'digest']);
    }
}
