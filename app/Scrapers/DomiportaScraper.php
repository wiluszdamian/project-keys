<?php

namespace App\Scrapers;

use App\Enums\DealType;
use App\Enums\PropertyType;
use App\Models\Listing;
use App\Models\Search;
use App\Scrapers\Concerns\FetchesPages;
use App\Scrapers\Concerns\ResolvesLocation;
use App\Scrapers\Contracts\EnrichesListings;
use App\Scrapers\Contracts\PortalScraper;
use App\Scrapers\Data\ListingData;
use App\Scrapers\Exceptions\ScraperException;
use App\Scrapers\Support\TextParser;
use Dom\HTMLDocument;
use Illuminate\Support\Arr;

/**
 * Domiporta. Filtry i sortowanie od najnowszych w adresie. Wyniki z ld+json (ItemList),
 * czynsz i dzielnica z kafelków HTML. Oferty "w promieniu 15 km" nie trafiają do ItemList.
 */
class DomiportaScraper implements EnrichesListings, PortalScraper
{
    use FetchesPages, ResolvesLocation;

    public function __construct(private HttpFetcher $fetcher) {}

    public function name(): string
    {
        return 'domiporta';
    }

    public function buildSearchUrl(Search $search, int $page = 1): string
    {
        $estate = $search->property_type === PropertyType::House ? 'dom' : 'mieszkanie';
        $deal = $search->deal_type === DealType::Rent ? 'wynajme' : 'sprzedam';

        $query = array_filter([
            'Price.From' => $search->price_min,
            'Price.To' => $search->price_max,
            'Surface.From' => $search->area_min,
            'Surface.To' => $search->area_max,
            'Rooms.From' => $search->rooms_min,
            'Rooms.To' => $search->rooms_max,
            'SortingOrderDirection' => 'SortDateDescending',
            'PageNumber' => $page > 1 ? $page : null,
        ], fn ($value) => $value !== null);

        return config('scrapers.domiporta.base_url')."/{$estate}/{$deal}/".$this->location($search->city).'?'.http_build_query($query);
    }

    public function parse(string $body, Search $search): array
    {
        $items = $this->itemList($body);
        $document = HTMLDocument::createFromString($body, LIBXML_NOERROR);
        $tiles = [];

        foreach ($document->querySelectorAll('article.sneakpeak[data-detail-id]') as $tile) {
            $tiles[$tile->getAttribute('data-detail-id')] = $tile;
        }

        $listings = [];

        foreach ($items as $item) {
            $url = $item['url'] ?? null;

            if (! $url || ! preg_match('#(\d+)$#', $url, $match)) {
                continue;
            }

            // Lokale deweloperów (/nowe/) mają osobną numerację.
            $id = str_contains($url, '/nowe/') ? "nowe-{$match[1]}" : $match[1];
            $tile = $tiles[$match[1]] ?? $tiles["/DPRP/{$match[1]}"] ?? null;
            $priceText = $tile?->querySelector('.sneakpeak__price_value')?->textContent ?? '';

            // Najem na doby to nie mieszkanie na stałe.
            if (str_contains($priceText, 'dob')) {
                continue;
            }

            $offer = $item['offers'] ?? [];
            [$district, $street] = $this->districtAndStreet($tile?->querySelector('.sneakpeak__title--inblock')?->textContent, Arr::get($offer, 'itemOffered.address'));
            $fee = $tile?->querySelector('.sneakpeak__price_fee')?->textContent;

            $listings[] = new ListingData(
                portal: $this->name(),
                externalId: $id,
                url: $url,
                title: trim($item['name'] ?? ''),
                dealType: $search->deal_type,
                price: isset($offer['price']) ? (int) round($offer['price']) : null,
                rentExtra: $fee ? TextParser::int($fee) : null,
                area: Arr::get($offer, 'itemOffered.floorSize.value'),
                rooms: Arr::get($offer, 'itemOffered.numberOfRooms'),
                city: Arr::get($offer, 'itemOffered.address.addressLocality'),
                district: $district,
                street: $street,
                // Sprzedawca z logo to biuro albo deweloper. Bez sprzedawcy nie wiadomo.
                isPrivateOwner: isset($offer['seller']['logo']) ? false : null,
                description: isset($item['description']) ? trim($item['description']) : null,
                imageUrl: $item['image'] ?? null,
                raw: array_filter([
                    'id' => $id,
                    'date_posted' => $item['datePosted'] ?? null,
                    'seller' => $offer['seller']['name'] ?? null,
                    'promoted' => $tile && str_contains($tile->textContent, 'WYRÓŻNIONE') ? true : null,
                ]),
            );
        }

        preg_match_all('/PageNumber=(\d+)/', $body, $pages);

        return ['listings' => $listings, 'total_pages' => $pages[1] ? max(array_map('intval', $pages[1])) : 1];
    }

    public function enrich(Listing $listing): bool
    {
        $details = $this->parseOffer($this->fetcher->get($this->name(), $listing->url));

        $listing->description = $details['description'] ?? $listing->description;
        $listing->rent_extra ??= $details['rent_extra'];
        $listing->floor ??= $details['floor'];
        $listing->is_private_owner ??= $details['is_private_owner'];
        $listing->raw = array_merge($listing->raw ?? [], ['details' => $details['details']]);
        $listing->content_hash = ListingData::hashOf($listing->title, $listing->price, $listing->area, $listing->description);

        return $listing->isDirty() && $listing->save();
    }

    /**
     * @return array{description: ?string, rent_extra: ?int, floor: ?string, is_private_owner: ?bool, details: array<string, string>}
     */
    public function parseOffer(string $html): array
    {
        $document = HTMLDocument::createFromString($html, LIBXML_NOERROR);
        $features = [];

        foreach ($document->querySelectorAll('.features__item_name') as $name) {
            $value = $name->nextElementSibling;

            if ($value) {
                $features[trim($name->textContent)] = trim(preg_replace('/\s+/u', ' ', $value->textContent));
            }
        }

        $description = $document->querySelector('.description__panel')?->textContent;
        $floor = $features['Piętro'] ?? null;
        $advertiser = preg_match("/'advertiserType':\s*'([^']+)'/", $html, $match) ? $match[1] : null;

        return [
            'description' => $description ? trim(preg_replace("/[ \t]+/", ' ', $description)) : null,
            'rent_extra' => isset($features['Czynsz administracyjny']) ? TextParser::int($features['Czynsz administracyjny']) : null,
            'floor' => match ($floor) {
                null, '' => null,
                '0', 'parter' => 'parter',
                default => $floor,
            },
            'is_private_owner' => match ($advertiser) {
                null => null,
                'prywatny', 'osoba prywatna', 'private' => true,
                default => false,
            },
            'details' => array_filter([
                'building_floors' => $features['Liczba pięter w budynku'] ?? null,
                'build_year' => $features['Rok budowy'] ?? null,
                'building_type' => $features['Typ budynku'] ?? null,
                'free_from' => $features['Dostępne od'] ?? null,
            ]),
        ];
    }

    /**
     * Ogłoszenia z ld+json (ItemList). Brak ItemList przy pustym wyniku nie jest błędem.
     *
     * @return list<array<string, mixed>>
     */
    private function itemList(string $body): array
    {
        preg_match_all('#<script type="application/ld\+json">(.*?)</script>#s', $body, $scripts);

        if ($scripts[1] === []) {
            throw new ScraperException('Domiporta: brak danych ld+json. Strona mogła się zmienić.');
        }

        foreach ($scripts[1] as $script) {
            $data = json_decode($script, true);

            foreach ($data['@graph'] ?? [$data] as $node) {
                if (($node['@type'] ?? null) === 'ItemList') {
                    return array_values(array_filter(array_map(fn ($element) => $element['item'] ?? null, $node['itemListElement'] ?? [])));
                }
            }
        }

        return [];
    }

    /**
     * "mieszkanie Rzeszów, Projektant, Dukielska" -> [Projektant, Dukielska].
     *
     * @return array{0: ?string, 1: ?string}
     */
    private function districtAndStreet(?string $title, ?array $address): array
    {
        $street = $address['streetAddress'] ?? null;
        $parts = array_map('trim', explode(',', preg_replace('/^\s*(mieszkanie|dom)\s+/u', '', (string) $title)));
        array_shift($parts); // miasto

        if ($street !== null && end($parts) === $street) {
            array_pop($parts);
        }

        return [$parts[0] ?? null, $street];
    }
}
