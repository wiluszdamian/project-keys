<?php

namespace App\Services\Geo;

use App\Models\Listing;
use App\Models\Search;
use App\Scrapers\Support\TextParser;

/**
 * Obszar wyszukiwania: miejscowość albo miejscowość z promieniem (np. Rzeszów + 10 km).
 * Środek i położenie innych miejscowości z OpenStreetMap (Geocoder, wyniki w cache).
 */
class SearchArea
{
    private const EARTH_KM = 6371.0;

    // Zapas na dokładność położenia oferty (km): pinezka i ulica są dokładne, dzielnica i miasto to środek obszaru.
    private const TOLERANCE_KM = [
        'portal' => 0.5,
        'street' => 0.5,
        'district' => 1.5,
        'city' => 1.5,
    ];

    public function __construct(private Geocoder $geocoder) {}

    /**
     * Środek wyszukiwania (miejscowość z wyszukiwania).
     *
     * @return array{lat: float, lng: float, label: string}|null
     */
    public function center(Search $search): ?array
    {
        return $this->geocoder->geocode("{$search->city}, Polska");
    }

    /**
     * Czy miejscowość z ogłoszenia jest w obszarze. Bez promienia tylko ta sama nazwa.
     * Z promieniem także miejscowości, których środek jest najdalej radius_km od środka wyszukiwania.
     * Gdy nie da się ustalić położenia, odpowiedź "nie" (oferta z nieznanej miejscowości nie przechodzi).
     */
    public function containsPlace(Search $search, ?string $place): bool
    {
        $place = trim((string) $place);

        if ($place === '') {
            return false;
        }

        if (TextParser::normalize($place) === TextParser::normalize($search->city)) {
            return true;
        }

        if (! $search->radius_km) {
            return false;
        }

        $center = $this->center($search);
        $point = $center ? $this->geocoder->geocode($place.$this->region($center['label'])) : null;

        return $point !== null && self::distanceKm($center['lat'], $center['lng'], $point['lat'], $point['lng']) <= $search->radius_km + self::TOLERANCE_KM['city'];
    }

    /**
     * Czy oferta z ustalonym położeniem jest w promieniu. Null, gdy nie da się tego sprawdzić
     * (wyszukiwanie bez promienia, oferta bez współrzędnych albo brak środka).
     */
    public function containsListing(Search $search, Listing $listing): ?bool
    {
        if (! $search->radius_km || ! $listing->hasCoordinates()) {
            return null;
        }

        $center = $this->center($search);

        if ($center === null) {
            return null;
        }

        $tolerance = self::TOLERANCE_KM[$listing->geo_precision] ?? self::TOLERANCE_KM['city'];

        return self::distanceKm($center['lat'], $center['lng'], $listing->lat, $listing->lng) <= $search->radius_km + $tolerance;
    }

    /**
     * Najmniejszy promień z listy portalu, który obejmuje promień wyszukiwania (np. 10 z [5, 10, 15]).
     *
     * @param  list<int>  $allowed
     */
    public static function portalRadius(?int $km, array $allowed): ?int
    {
        if (! $km) {
            return null;
        }

        foreach ($allowed as $value) {
            if ($value >= $km) {
                return $value;
            }
        }

        return end($allowed) ?: null;
    }

    public static function distanceKm(float $lat1, float $lng1, float $lat2, float $lng2): float
    {
        $dLat = deg2rad($lat2 - $lat1);
        $dLng = deg2rad($lng2 - $lng1);
        $a = sin($dLat / 2) ** 2 + cos(deg2rad($lat1)) * cos(deg2rad($lat2)) * sin($dLng / 2) ** 2;

        return 2 * self::EARTH_KM * asin(min(1, sqrt($a)));
    }

    /**
     * Województwo z opisu środka ("…, województwo podkarpackie, Polska"), żeby szukać wsi w tym samym regionie
     * (np. Krasne jest w kilku województwach).
     */
    private function region(string $label): string
    {
        return preg_match('/województwo [\p{L}-]+/u', $label, $match) ? ", {$match[0]}" : ', Polska';
    }
}
