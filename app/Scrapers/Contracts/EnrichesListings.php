<?php

namespace App\Scrapers\Contracts;

use App\Models\Listing;

/**
 * Portal, którego lista wyników nie ma wszystkich danych (np. opisu).
 * Dla nowych ofert pobieramy wtedy stronę ogłoszenia, raz, przed oceną AI.
 */
interface EnrichesListings
{
    /**
     * Uzupełnia ofertę danymi ze strony ogłoszenia. Zwraca true, gdy coś zmienił.
     */
    public function enrich(Listing $listing): bool;
}
