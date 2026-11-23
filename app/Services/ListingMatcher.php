<?php

namespace App\Services;

use App\Models\Listing;
use App\Models\Search;
use App\Scrapers\Data\ListingData;
use Illuminate\Support\Str;

/**
 * Dodatkowe filtrowanie po pobraniu: dzielnice i to, czego portal nie filtruje w adresie.
 * Brak wartości w ogłoszeniu (np. brak metrażu) nie odrzuca oferty, z wyjątkiem dzielnicy.
 */
class ListingMatcher
{
    public function matches(Search $search, ListingData $listing): bool
    {
        return $listing->dealType === $search->deal_type
            && $this->inRange($listing->price, $search->price_min, $search->price_max)
            && $this->inRange($listing->area, $search->area_min, $search->area_max)
            && $this->inRange($listing->rooms, $search->rooms_min, $search->rooms_max)
            && $this->districtMatches($search, $listing->district)
            && $this->featuresMatch($search, ListingFeatures::of($listing));
    }

    /**
     * To samo dla zapisanej oferty, np. po pobraniu strony ogłoszenia (pełny opis, rynek, wyposażenie).
     */
    public function matchesListing(Search $search, Listing $listing): bool
    {
        return $listing->deal_type === $search->deal_type
            && $this->inRange($listing->price, $search->price_min, $search->price_max)
            && $this->inRange($listing->area !== null ? (float) $listing->area : null, $search->area_min, $search->area_max)
            && $this->inRange($listing->rooms, $search->rooms_min, $search->rooms_max)
            && $this->districtMatches($search, $listing->district)
            && $this->featuresMatch($search, ListingFeatures::of($listing));
    }

    /**
     * Rynek, meble i wizualizacje. Odrzuca tylko oferty, o których wiemy, że nie pasują.
     * Gdy ogłoszenie nic nie mówi (np. o meblach), decyduje ocena AI.
     */
    public function featuresMatch(Search $search, ListingFeatures $features): bool
    {
        if ($search->market && $features->market && $features->market !== $search->market) {
            return false;
        }

        if ($search->furnished_only && $features->furnished === false) {
            return false;
        }

        return ! ($search->exclude_visualizations && $features->visualizations);
    }

    private function inRange(int|float|null $value, ?int $min, ?int $max): bool
    {
        if ($value === null) {
            return true;
        }

        return ($min === null || $value >= $min) && ($max === null || $value <= $max);
    }

    private function districtMatches(Search $search, ?string $district): bool
    {
        if (empty($search->districts)) {
            return true;
        }

        // Gdy wyszukiwanie ma dzielnice, a ogłoszenie nie podaje dzielnicy, odrzucamy je.
        if ($district === null) {
            return false;
        }

        $wanted = array_map($this->normalize(...), $search->districts);

        return in_array($this->normalize($district), $wanted, true);
    }

    private function normalize(string $value): string
    {
        return Str::of($value)->ascii()->lower()->squish()->toString();
    }
}
