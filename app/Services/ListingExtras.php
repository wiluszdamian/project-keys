<?php

namespace App\Services;

use App\Models\Listing;
use App\Services\Geo\CommuteCalculator;
use App\Services\Geo\ListingLocator;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Dodatkowe dane oferty przed oceną AI: współrzędne, czasy dojazdu, hash zdjęcia i sygnały ryzyka.
 * Każdy krok może się nie udać i nie blokuje oceny.
 */
class ListingExtras
{
    public function __construct(
        private ListingLocator $locator,
        private CommuteCalculator $commutes,
        private ImageHasher $hasher,
        private RiskDetector $risks,
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
