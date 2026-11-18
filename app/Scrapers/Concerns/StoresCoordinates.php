<?php

namespace App\Scrapers\Concerns;

use App\Models\Listing;

/**
 * Pinezka ze strony ogłoszenia. Dokładniejsza niż adres z geokodowania, więc ją nadpisuje.
 */
trait StoresCoordinates
{
    /**
     * @param  array{0: float, 1: float}|null  $coordinates
     */
    protected function applyCoordinates(Listing $listing, ?array $coordinates): void
    {
        if ($coordinates === null) {
            return;
        }

        $listing->lat = $coordinates[0];
        $listing->lng = $coordinates[1];
        $listing->geo_precision = 'portal';
        $listing->geocoded_at = now();
    }
}
