<?php

namespace App\Services;

use App\Enums\DealType;
use App\Models\Listing;
use App\Services\Geo\CommuteCalculator;
use App\Services\Geo\Infrastructure;
use App\Services\Geo\ListingLocator;
use App\Services\Rcn\TransactionComparator;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Dodatkowe dane oferty przed oceną AI: współrzędne, czasy dojazdu, hash zdjęcia, sygnały ryzyka i ceny transakcyjne.
 * Każdy krok może się nie udać i nie blokuje oceny.
 */
class ListingExtras
{
    public function __construct(
        private ListingLocator $locator,
        private CommuteCalculator $commutes,
        private ImageHasher $hasher,
        private RiskDetector $risks,
        private TransactionComparator $transactions,
        private DealFinder $deals,
        private Infrastructure $infrastructure,
    ) {}

    public function prepare(Listing $listing): void
    {
        $this->attempt('geo', fn () => $this->locator->locate($listing) && $this->commutes->forListings(collect([$listing])));

        $this->attempt('image', function () use ($listing) {
            if ($listing->image_hash === null && $listing->image_url) {
                $listing->forceFill(['image_hash' => $this->hasher->hashUrl($listing->image_url) ?? ''])->save();
            }
        });

        $this->attempt('risk', fn () => $listing->forceFill(['risk_flags' => $this->risks->detect($listing) ?: null])->save());

        // Bez współrzędnych nie porównujemy: listings:enrich-missing zrobi to po geokodowaniu.
        if (config('rcn.enabled') && $listing->deal_type === DealType::Sale && $listing->hasCoordinates()) {
            $this->attempt('transactions', fn () => $listing->forceFill([
                'transaction_comparison' => $this->transactions->compare($listing),
                'transactions_compared_at' => now(),
            ])->save());
        }

        $this->refreshDeal($listing);

        // Sklepy, żłobek, przedszkole i szkoły w okolicy (raz na ofertę, po ustaleniu położenia).
        if ($listing->hasCoordinates() && $listing->infrastructure_at === null) {
            $this->attempt('infrastructure', fn () => $this->infrastructure->refresh($listing));
        }
    }

    /**
     * Po cenach transakcyjnych, bo od nich zależy okazja przy sprzedaży.
     */
    public function refreshDeal(Listing $listing): void
    {
        $this->attempt('deal', fn () => $this->deals->refresh($listing));
    }

    private function attempt(string $step, callable $callback): void
    {
        try {
            $callback();
        } catch (Throwable $exception) {
            Log::warning("Dodatkowe dane oferty: krok {$step} nie wyszedł", ['error' => $exception->getMessage()]);
        }
    }
}
