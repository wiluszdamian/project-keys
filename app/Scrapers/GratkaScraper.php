<?php

namespace App\Scrapers;

use App\Enums\DealType;
use App\Enums\PropertyType;
use App\Models\Search;

/**
 * Gratka. Te same oferty co Morizon (ta sama firma). Sortowanie od najnowszych jest tylko
 * jako ?sort=, którego robots.txt zabrania, więc kolejność domyślna (ostatnio odświeżone).
 */
class GratkaScraper extends MorizonNetworkScraper
{
    public function name(): string
    {
        return 'gratka';
    }

    public function buildSearchUrl(Search $search, int $page = 1): string
    {
        $estate = $search->property_type === PropertyType::House ? 'domy' : 'mieszkania';
        $suffix = $search->deal_type === DealType::Rent ? '/wynajem' : '';

        return config('scrapers.gratka.base_url')."/nieruchomosci/{$estate}/".$this->location($search->city).$suffix
            .($page > 1 ? "?page={$page}" : '');
    }

    protected function imageUrl(string $id, string $name): string
    {
        return "https://thumbs.cdngr.pl/thumb/{$id}/3x2_xs:fill_and_crop/{$name}.jpg";
    }
}
