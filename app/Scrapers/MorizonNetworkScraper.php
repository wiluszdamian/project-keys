<?php

namespace App\Scrapers;

use App\Models\Listing;
use App\Models\Search;
use App\Scrapers\Concerns\FetchesPages;
use App\Scrapers\Concerns\ResolvesLocation;
use App\Scrapers\Contracts\EnrichesListings;
use App\Scrapers\Contracts\PortalScraper;
use App\Scrapers\Data\ListingData;
use App\Scrapers\Exceptions\ScraperException;
use App\Scrapers\Support\NuxtPayload;
use App\Scrapers\Support\TextParser;
use App\Support\Portals;
use Illuminate\Support\Arr;
use Illuminate\Support\Str;

/**
 * Morizon i Gratka: ta sama firma, te same oferty i te same id, inne adresy.
 * Dane z __NUXT_DATA__. Filtry w adresie zabrania robots.txt, więc filtruje ListingMatcher.
 */
abstract class MorizonNetworkScraper implements EnrichesListings, PortalScraper
{
    use FetchesPages, ResolvesLocation;

    public function __construct(protected HttpFetcher $fetcher) {}

    /**
     * Adres zdjęcia z id i nazwy z danych strony.
     */
    abstract protected function imageUrl(string $id, string $name): string;

    public function parse(string $body, Search $search): array
    {
        $data = $this->payload($body);
        $result = collect($data)->first(fn ($value, $key) => str_starts_with($key, 'property-listing-data-'));
        $searchResult = Arr::get($result ?? [], 'data.searchResult');

        if (! is_array($searchResult)) {
            throw new ScraperException("{$this->label()}: brak listy ofert w danych strony. Strona mogła się zmienić.");
        }

        // Dwa kształty: grupy (oferta w "primary") albo zwykła lista ofert.
        $block = $searchResult['propertyGroups'] ?? $searchResult['properties'] ?? [];
        $nodes = array_map(fn ($node) => $node['primary'] ?? $node, $block['nodes'] ?? []);
        $total = (int) ($block['totalCount'] ?? 0);

        return [
            'listings' => array_values(array_filter(array_map(fn ($node) => is_array($node) ? $this->toListingData($node, $search) : null, $nodes))),
            'total_pages' => max(1, (int) ceil($total / 35)),
        ];
    }

    public function enrich(Listing $listing): bool
    {
        $details = $this->parseOffer($this->fetcher->get($this->name(), $listing->url));

        $listing->description = $details['description'] ?? $listing->description;
        $listing->area = $details['area'] ?? $listing->area;
        $listing->floor ??= $details['floor'];
        $listing->is_private_owner ??= $details['is_private_owner'];
        $listing->price_per_m2 = $listing->price && $listing->area ? (int) round($listing->price / (float) $listing->area) : $listing->price_per_m2;
        $listing->raw = array_merge($listing->raw ?? [], ['details' => $details['details']]);
        $listing->content_hash = ListingData::hashOf($listing->title, $listing->price, $listing->area, $listing->description);

        return $listing->isDirty() && $listing->save();
    }

    /**
     * Strona oferty: pełny opis, dokładny metraż, piętro i fakty o budynku. Czynszu portal nie podaje osobno.
     *
     * @return array{description: ?string, area: ?float, floor: ?string, is_private_owner: ?bool, details: array<string, string>}
     */
    public function parseOffer(string $html): array
    {
        $data = $this->payload($html);
        $details = collect($data)->first(fn ($value, $key) => str_starts_with($key, 'property-details-'));
        $property = Arr::get($details ?? [], 'data.propertyData');

        if (! is_array($property)) {
            throw new ScraperException("{$this->label()}: brak danych oferty na stronie ogłoszenia.");
        }

        $info = collect([...($property['detailedInformation'] ?? []), ...($property['buildingDetailedInformation'] ?? [])])
            ->filter(fn ($row) => is_array($row) && isset($row['label']))
            ->mapWithKeys(fn ($row) => [$row['label'] => is_scalar($row['value'] ?? null) ? (string) $row['value'] : null]);

        $description = isset($property['description'])
            ? TextParser::htmlToText(str_replace("\u{00A0}", ' ', (string) $property['description']))
            : null;

        return [
            'description' => $description ?: null,
            'area' => isset($property['area']) ? TextParser::number((string) $property['area']) : null,
            'floor' => $this->floor($info['Piętro'] ?? (isset($property['floor']) ? (string) $property['floor'] : null)),
            'is_private_owner' => $this->isPrivate($property['contact'] ?? []),
            'details' => array_filter([
                'building_floors' => $info['Liczba pięter'] ?? null,
                'build_year' => $info['Rok budowy'] ?? null,
                'heating' => $info['Ogrzewanie'] ?? null,
                'building_type' => $info['Typ budynku'] ?? null,
                'condition' => $info['Stan nieruchomości'] ?? null,
                'market' => $info['Rynek'] ?? null,
                'free_from' => $info['Dostępne od'] ?? null,
            ]),
        ];
    }

    protected function toListingData(array $node, Search $search): ?ListingData
    {
        if (! isset($node['id'], $node['url'])) {
            return null;
        }

        $location = array_values(array_filter($node['location']['location'] ?? [], 'is_string'));
        // Miasto: ["podkarpackie", "Rzeszów", "Śródmieście"]. Wieś: ["podkarpackie", "rzeszowski", "Głogów Małopolski", "Rudna Mała"].
        $isCity = isset($location[1]) && $location[1] !== mb_strtolower($location[1]);
        $city = $isCity ? $location[1] : (end($location) ?: null);
        $photo = $node['photos'][0] ?? null;
        $title = trim((string) ($node['advertisementText'] ?: ($node['title'] ?? '')));

        return new ListingData(
            portal: $this->name(),
            externalId: (string) $node['id'],
            url: config("scrapers.{$this->name()}.base_url").$node['url'],
            title: $title !== '' ? $title : 'Mieszkanie',
            dealType: $search->deal_type,
            price: isset($node['price']['amount']) ? (int) round((float) $node['price']['amount']) : null,
            area: isset($node['area']) ? TextParser::number((string) $node['area']) : null,
            rooms: isset($node['numberOfRooms']) ? TextParser::int((string) $node['numberOfRooms']) : null,
            floor: $this->floor($node['floorFormatted'] ?? null),
            city: $city,
            district: $isCity ? ($location[2] ?? null) : null,
            street: $node['location']['street'] ?? null,
            isPrivateOwner: $this->isPrivate($node['contact'] ?? []),
            description: isset($node['description']) ? trim((string) $node['description']) : null,
            imageUrl: isset($photo['id'], $photo['name']) ? $this->imageUrl($photo['id'], $photo['name']) : null,
            raw: array_filter([
                'network_id' => (string) $node['id'],
                'added_at' => $node['addedAt'] ?? null,
                'refreshed_at' => $node['refreshedAt'] ?? null,
                'seller' => $node['contact']['company']['name'] ?? null,
                'development' => isset($node['development']) && $node['development'] ? true : null,
            ]),
        );
    }

    /**
     * "parter/1" -> parter, "piętro 6/14" albo "6/14" -> 6.
     */
    protected function floor(?string $text): ?string
    {
        if ($text === null || trim($text) === '') {
            return null;
        }

        if (Str::contains(mb_strtolower($text), 'parter') || preg_match('/^\s*0\s*(\/|$)/', $text)) {
            return 'parter';
        }

        return preg_match('/(-?\d+)/', $text, $match) ? $match[1] : null;
    }

    protected function isPrivate(array $contact): ?bool
    {
        $types = array_filter([$contact['company']['type'] ?? null, $contact['person']['type'] ?? null]);

        return match (true) {
            in_array('OWNER', $types, true) => true,
            isset($contact['company']) && $contact['company'] !== null => false,
            $types !== [] => false,
            default => null,
        };
    }

    private function payload(string $html): array
    {
        $data = NuxtPayload::fromHtml($html)['data'] ?? null;

        if (! is_array($data)) {
            throw new ScraperException("{$this->label()}: brak __NUXT_DATA__. Strona mogła się zmienić albo zablokowała zapytanie.");
        }

        return $data;
    }

    private function label(): string
    {
        return Portals::label($this->name());
    }
}
