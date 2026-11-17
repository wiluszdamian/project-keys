<?php

namespace App\Scrapers\Data;

final readonly class ScrapeResult
{
    /**
     * @param  list<ListingData>  $listings
     */
    public function __construct(
        public array $listings,
        public int $pagesFetched,
    ) {}
}
