<?php

namespace App\Services;

use App\Models\Listing;
use App\Models\ListingPriceChange;
use App\Models\Search;
use App\Scrapers\Data\ListingData;
use Illuminate\Support\Facades\DB;

/**
 * Zapisuje ogłoszenia: nowe dodaje (i oznacza duplikaty z innych portali),
 * istniejące aktualizuje i zapisuje zmiany ceny.
 */
class ListingUpserter
{
    public function __construct(private DuplicateDetector $duplicates) {}

    public function upsert(ListingData $data): UpsertResult
    {
        return DB::transaction(function () use ($data) {
            $now = now();

            $listing = Listing::query()
                ->where('portal', $data->portal)
                ->where('external_id', $data->externalId)
                ->lockForUpdate()
                ->first();

            if ($listing === null) {
                $listing = Listing::create($data->toAttributes() + [
                    'first_seen_at' => $now,
                    'last_seen_at' => $now,
                ]);

                if ($original = $this->duplicates->findOriginal($listing)) {
                    $listing->update(['duplicate_of_id' => $original->id]);
                }

                return new UpsertResult($listing, isNew: true);
            }

            $oldPrice = $listing->price;
            $listing->fill($data->toAttributes() + ['last_seen_at' => $now])->save();

            $priceChanged = $data->price !== null && $oldPrice !== null && $oldPrice !== $data->price;

            if ($priceChanged) {
                ListingPriceChange::create([
                    'listing_id' => $listing->id,
                    'old_price' => $oldPrice,
                    'new_price' => $data->price,
                    'changed_at' => $now,
                ]);
            }

            return new UpsertResult($listing, isNew: false, priceChanged: $priceChanged, oldPrice: $oldPrice);
        });
    }

    /**
     * Łączy ogłoszenie z wyszukiwaniem. Zwraca true, gdy to nowe dopasowanie.
     */
    public function attach(Search $search, Listing $listing): bool
    {
        return DB::table('search_listing')->insertOrIgnore([
            'search_id' => $search->id,
            'listing_id' => $listing->id,
            'matched_at' => now(),
        ]) === 1;
    }
}
