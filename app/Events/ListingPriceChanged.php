<?php

namespace App\Events;

use App\Models\Listing;
use App\Models\Search;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Zmiana ceny ogłoszenia, które już było dopasowane do wyszukiwania.
 */
class ListingPriceChanged
{
    use Dispatchable, SerializesModels;

    public function __construct(
        public Search $search,
        public Listing $listing,
        public int $oldPrice,
        public int $newPrice,
    ) {}
}
