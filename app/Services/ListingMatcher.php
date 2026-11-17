<?php

namespace App\Services;

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
            && $this->districtMatches($search, $listing);
    }

    private function inRange(int|float|null $value, ?int $min, ?int $max): bool
    {
        if ($value === null) {
            return true;
        }

        return ($min === null || $value >= $min) && ($max === null || $value <= $max);
    }

    private function districtMatches(Search $search, ListingData $listing): bool
    {
        if (empty($search->districts)) {
            return true;
        }

        // Gdy wyszukiwanie ma dzielnice, a ogłoszenie nie podaje dzielnicy, odrzucamy je.
        if ($listing->district === null) {
            return false;
        }

        $wanted = array_map($this->normalize(...), $search->districts);

        return in_array($this->normalize($listing->district), $wanted, true);
    }

    private function normalize(string $value): string
    {
        return Str::of($value)->ascii()->lower()->squish()->toString();
    }
}
