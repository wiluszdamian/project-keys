<?php

namespace App\Events;

use App\Models\Listing;
use App\Models\Search;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Nowe dopasowanie ogłoszenia do wyszukiwania. Słuchają go ocena AI (faza 6) i powiadomienia (faza 7).
 */
class ListingMatched
{
    use Dispatchable, SerializesModels;

    public function __construct(
        public Search $search,
        public Listing $listing,
        public bool $duringFirstRun,
    ) {}
}
