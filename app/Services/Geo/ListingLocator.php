<?php

namespace App\Services\Geo;

use App\Models\Listing;

/**
 * Współrzędne oferty: najpierw pinezka z portalu, potem adres z ulicą, dzielnica, na końcu samo miasto.
 */
class ListingLocator
{
    public function __construct(private Geocoder $geocoder) {}

    /**
     * Zwraca true, gdy oferta ma współrzędne (już miała albo właśnie je dostała).
     */
    public function locate(Listing $listing): bool
    {
        if ($listing->hasCoordinates() && $listing->geo_precision !== null) {
            return true;
        }

        if ($pin = $this->fromPortal($listing)) {
            return $this->save($listing, $pin[0], $pin[1], 'portal');
        }

        if (! $listing->city) {
            return false;
        }

        $street = $this->cleanStreet($listing->street);

        foreach (array_filter([
            $street ? ['street', "{$street}, {$listing->city}"] : null,
            $listing->district ? ['district', "{$listing->district}, {$listing->city}"] : null,
            ['city', $listing->city],
        ]) as [$precision, $query]) {
            if ($result = $this->geocoder->geocode("{$query}, Polska")) {
                return $this->save($listing, $result['lat'], $result['lng'], $precision);
            }
        }

        $listing->forceFill(['geocoded_at' => now()])->save();

        return false;
    }

    /**
     * Współrzędne zapisane przez scraper (OLX na liście, inne portale ze strony ogłoszenia).
     *
     * @return array{0: float, 1: float}|null
     */
    private function fromPortal(Listing $listing): ?array
    {
        if ($listing->hasCoordinates()) {
            return [$listing->lat, $listing->lng];
        }

        $map = $listing->raw['map'] ?? null;

        // OLX: dokładna pinezka tylko przy show_detailed. Inaczej to środek okręgu o promieniu kilku km.
        if (isset($map['lat'], $map['lon']) && ($map['show_detailed'] ?? false)) {
            return [(float) $map['lat'], (float) $map['lon']];
        }

        return null;
    }

    private function cleanStreet(?string $street): ?string
    {
        if ($street === null) {
            return null;
        }

        $street = trim(preg_replace('/^(ul\.|al\.|os\.|pl\.)\s*/iu', '', $street));

        // Sam numer domu (np. "166") bez nazwy ulicy nic nie daje.
        return preg_match('/\p{L}{3,}/u', $street) ? $street : null;
    }

    private function save(Listing $listing, float $lat, float $lng, string $precision): bool
    {
        $listing->forceFill(['lat' => $lat, 'lng' => $lng, 'geo_precision' => $precision, 'geocoded_at' => now()])->save();

        return true;
    }
}
