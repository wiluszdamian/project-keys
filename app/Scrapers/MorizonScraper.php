<?php

namespace App\Scrapers;

use App\Enums\DealType;
use App\Enums\PropertyType;
use App\Models\Search;

/**
 * Morizon. Sortowanie od najnowszych jako fragment ścieżki (/najnowsze/), dozwolone w robots.txt.
 */
class MorizonScraper extends MorizonNetworkScraper
{
    public function name(): string
    {
        return 'morizon';
    }

    public function buildSearchUrl(Search $search, int $page = 1): string
    {
        $estate = $search->property_type === PropertyType::House ? 'domy' : 'mieszkania';
        $prefix = $search->deal_type === DealType::Rent ? '/do-wynajecia' : '';

        return config('scrapers.morizon.base_url')."{$prefix}/{$estate}/najnowsze/".$this->location($search->city).'/'
            .($page > 1 ? "?page={$page}" : '');
    }

    protected function imageUrl(string $id, string $name): string
    {
        return "https://img1.staticmorizon.com.pl/thumb/{$id}/3x2_xs:fill_and_crop/{$name}.jpg";
    }
}
