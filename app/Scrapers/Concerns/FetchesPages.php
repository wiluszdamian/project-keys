<?php

namespace App\Scrapers\Concerns;

use App\Models\Search;
use App\Scrapers\Data\ScrapeResult;

/**
 * Wspólna pętla: kolejne strony wyników do SCRAPER_MAX_PAGES, bez powtórzeń ofert.
 * Klasa podaje name(), buildSearchUrl() i parse(), a w razie potrzeby nadpisuje requestHeaders().
 */
trait FetchesPages
{
    public function fetchListings(Search $search): ScrapeResult
    {
        $listings = [];
        $pages = 0;

        for ($page = 1; $page <= config('scrapers.max_pages'); $page++) {
            $parsed = $this->parse($this->fetcher->get($this->name(), $this->buildSearchUrl($search, $page), $this->requestHeaders()), $search);
            $pages++;
            $before = count($listings);

            foreach ($parsed['listings'] as $listing) {
                $listings[$listing->externalId] ??= $listing;
            }

            // Koniec wyników albo portal oddaje w kółko tę samą stronę.
            if ($parsed['listings'] === [] || count($listings) === $before || $page >= ($parsed['total_pages'] ?? 1)) {
                break;
            }
        }

        return new ScrapeResult(array_values($listings), $pages);
    }

    /**
     * @return array<string, string>
     */
    protected function requestHeaders(): array
    {
        return [];
    }
}
