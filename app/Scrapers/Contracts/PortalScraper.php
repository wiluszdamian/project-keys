<?php

namespace App\Scrapers\Contracts;

use App\Models\Search;
use App\Scrapers\Data\ListingData;
use App\Scrapers\Data\ScrapeResult;

interface PortalScraper
{
    /**
     * Nazwa portalu, taka jak w searches.portals, np. "otodom".
     */
    public function name(): string;

    /**
     * Adres strony wyników dla wyszukiwania, sortowanie od najnowszych.
     */
    public function buildSearchUrl(Search $search, int $page = 1): string;

    /**
     * Pobiera do SCRAPER_MAX_PAGES stron wyników.
     */
    public function fetchListings(Search $search): ScrapeResult;

    /**
     * Odczytuje ogłoszenia z jednej strony wyników.
     *
     * @return array{listings: list<ListingData>, total_pages: int|null}
     */
    public function parse(string $html): array;
}
