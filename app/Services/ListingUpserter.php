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
    // Dane ze strony ogłoszenia (EnrichesListings), których lista wyników nie podaje.
    private const KEEP_WHEN_MISSING = ['description', 'rent_extra', 'street', 'is_private_owner', 'image_url', 'district', 'area', 'rooms', 'floor'];

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
            $oldHash = $listing->content_hash;
            $attributes = $data->toAttributes();

            // Dane ze strony ogłoszenia (opis, czynsz) nie znikają, gdy lista wyników ich nie podaje.
            foreach (self::KEEP_WHEN_MISSING as $field) {
                if ($attributes[$field] === null && $listing->{$field} !== null) {
                    unset($attributes[$field]);
                }
            }

            if (isset($listing->raw['details']) && ! isset($attributes['raw']['details'])) {
                $attributes['raw']['details'] = $listing->raw['details'];
            }

            $listing->fill($attributes + ['last_seen_at' => $now]);
            $listing->content_hash = ListingData::hashOf($listing->title, $listing->price, $listing->area, $listing->description);
            $listing->save();

            $priceChanged = $data->price !== null && $oldPrice !== null && $oldPrice !== $data->price;

            if ($priceChanged) {
                ListingPriceChange::create([
                    'listing_id' => $listing->id,
                    'old_price' => $oldPrice,
                    'new_price' => $data->price,
                    'changed_at' => $now,
                ]);
            }

            return new UpsertResult($listing, isNew: false, priceChanged: $priceChanged, oldPrice: $oldPrice, contentChanged: $oldHash !== $listing->content_hash);
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
