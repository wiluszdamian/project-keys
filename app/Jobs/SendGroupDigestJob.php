<?php

namespace App\Jobs;

use App\Enums\DealType;
use App\Models\Listing;
use App\Models\ListingAnalysis;
use App\Notifications\Notifier;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\DB;

/**
 * Jedno podsumowanie kanału (wynajem albo sprzedaż) ze wszystkich wyszukiwań tego typu.
 * Oferta pasująca do kilku wyszukiwań jest raz, z nazwami tych wyszukiwań.
 */
class SendGroupDigestJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 5;

    public array $backoff = [30, 120, 600, 1800];

    /**
     * @param  list<int>  $rowIds  id wierszy search_listing czekających na podsumowanie
     * @param  list<array{listing_id: int, search_id: int, old_price: int, new_price: int}>  $priceDrops
     */
    public function __construct(public DealType $dealType, public array $rowIds, public array $priceDrops, public string $period)
    {
        $this->onQueue('notify');
    }

    public function handle(Notifier $notifier): void
    {
        $rows = DB::table('search_listing')
            ->join('searches', 'searches.id', '=', 'search_listing.search_id')
            ->whereIn('search_listing.id', $this->rowIds)
            ->whereNull('search_listing.notified_at')
            ->get(['search_listing.id', 'search_listing.listing_id', 'search_listing.search_id', 'searches.name']);

        $analyses = ListingAnalysis::whereIn('listing_id', $rows->pluck('listing_id'))
            ->whereIn('search_id', $rows->pluck('search_id'))
            ->get()
            ->groupBy('listing_id');

        $listings = Listing::whereKey($rows->pluck('listing_id')->unique())->get()->map(function (Listing $listing) use ($rows, $analyses) {
            $best = $analyses->get($listing->id)?->sortByDesc('score')->first();

            return $listing
                ->setAttribute('score', $best?->score)
                ->setAttribute('monthly_cost', $best?->monthly_cost)
                ->setAttribute('search_names', $rows->where('listing_id', $listing->id)->pluck('name')->unique()->implode(', '));
        });

        $top = $listings
            ->sortBy([fn ($a, $b) => ($b->getAttribute('score') ?? -1) <=> ($a->getAttribute('score') ?? -1), fn ($a, $b) => $b->first_seen_at <=> $a->first_seen_at])
            ->take(config('notify.digest_size'))
            ->values();

        $drops = collect($this->priceDrops)
            ->map(fn (array $drop) => ['listing' => Listing::find($drop['listing_id']), 'old_price' => $drop['old_price'], 'new_price' => $drop['new_price']])
            ->filter(fn (array $drop) => $drop['listing'] !== null)
            ->values()
            ->all();

        $notifier->groupDigest($this->dealType, $top, $listings->count(), $drops, $this->period);

        $topIds = $top->modelKeys();
        DB::table('search_listing')->whereIn('id', $rows->whereIn('listing_id', $topIds)->pluck('id'))
            ->update(['notified_at' => now(), 'notify_skipped_reason' => null]);
        DB::table('search_listing')->whereIn('id', $rows->whereNotIn('listing_id', $topIds)->pluck('id'))
            ->update(['notify_skipped_reason' => 'digest']);
    }
}
