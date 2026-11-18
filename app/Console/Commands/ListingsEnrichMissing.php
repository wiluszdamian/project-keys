<?php

namespace App\Console\Commands;

use App\Models\CommutePlace;
use App\Models\Listing;
use App\Services\Geo\CommuteCalculator;
use App\Services\Geo\ListingLocator;
use App\Services\ImageHasher;
use App\Services\RiskDetector;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Builder;

#[Signature('listings:enrich-missing {--geocode=120 : Ile ofert geokodować (OpenStreetMap, 1 na sekundę)} {--images=150 : Ile zdjęć pobrać do porównania}')]
#[Description('Uzupełnia aktualne oferty: współrzędne, czasy dojazdu, hash zdjęcia i sygnały ryzyka')]
class ListingsEnrichMissing extends Command
{
    public function handle(ListingLocator $locator, CommuteCalculator $commutes, ImageHasher $hasher, RiskDetector $risks): int
    {
        $located = 0;

        foreach ($this->current()->whereNull('geocoded_at')->orderByDesc('first_seen_at')->limit((int) $this->option('geocode'))->get() as $listing) {
            $located += (int) $locator->locate($listing);
        }

        $places = CommutePlace::usable()->get();
        $routed = 0;

        if ($places->isNotEmpty()) {
            $this->current()->whereNotNull('lat')
                ->whereHas('commutes', fn (Builder $query) => $query->whereIn('commute_place_id', $places->modelKeys()), '<', $places->count() * 2)
                ->chunkById(config('geo.batch'), function ($chunk) use ($commutes, $places, &$routed) {
                    $routed += $commutes->forListings($chunk, $places);
                });
        }

        $hashed = 0;

        foreach ($this->current()->whereNull('image_hash')->whereNotNull('image_url')->orderByDesc('first_seen_at')->limit((int) $this->option('images'))->get() as $listing) {
            $listing->forceFill(['image_hash' => $hasher->hashUrl($listing->image_url) ?? ''])->save();
            $listing->forceFill(['risk_flags' => $risks->detect($listing) ?: null])->save();
            $hashed++;
        }

        $this->info("Współrzędne: {$located}, czasy dojazdu: {$routed}, zdjęcia: {$hashed}.");

        return self::SUCCESS;
    }

    /**
     * Aktualne oferty (widziane w ostatnich 30 dniach), bez duplikatów z innych portali.
     */
    private function current(): Builder
    {
        return Listing::query()->whereNull('duplicate_of_id')->where('last_seen_at', '>=', now()->subDays(30));
    }
}
