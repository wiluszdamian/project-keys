<?php

namespace App\Console\Commands;

use App\Enums\DealType;
use App\Models\CommutePlace;
use App\Models\Listing;
use App\Services\Geo\CommuteCalculator;
use App\Services\Geo\Infrastructure;
use App\Services\Geo\ListingLocator;
use App\Services\ImageHasher;
use App\Services\Rcn\TransactionComparator;
use App\Services\RiskDetector;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Builder;

#[Signature('listings:enrich-missing {--geocode=120 : Ile ofert geokodować (OpenStreetMap, 1 na sekundę)} {--images=150 : Ile zdjęć pobrać do porównania}')]
#[Description('Uzupełnia aktualne oferty: współrzędne, czasy dojazdu, hash zdjęcia, sygnały ryzyka, ceny transakcyjne i infrastrukturę')]
class ListingsEnrichMissing extends Command
{
    public function handle(ListingLocator $locator, CommuteCalculator $commutes, ImageHasher $hasher, RiskDetector $risks, TransactionComparator $transactions, Infrastructure $infrastructure): int
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

        $compared = 0;

        if (config('rcn.enabled')) {
            // Oferty sprzedaży, które dostały współrzędne po ocenie: porównanie z transakcjami z RCN.
            $this->current()->where('deal_type', DealType::Sale)->whereNotNull('lat')->whereNull('transactions_compared_at')
                ->chunkById(200, function ($chunk) use ($transactions, &$compared) {
                    foreach ($chunk as $listing) {
                        $listing->forceFill(['transaction_comparison' => $transactions->compare($listing), 'transactions_compared_at' => now()])->save();
                        $compared++;
                    }
                });
        }

        // Sklepy, żłobek, przedszkole i szkoły dla ofert, które dostały współrzędne później.
        $surroundings = 0;

        foreach ($this->current()->whereNotNull('lat')->whereNull('infrastructure_at')->orderByDesc('first_seen_at')->limit(100)->get() as $listing) {
            $surroundings += (int) $infrastructure->refresh($listing);
        }

        $this->info("Współrzędne: {$located}, czasy dojazdu: {$routed}, zdjęcia: {$hashed}, ceny transakcyjne: {$compared}, infrastruktura: {$surroundings}.");

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
