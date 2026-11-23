<?php

namespace App\Scrapers;

use App\Enums\DealType;
use App\Enums\PropertyType;
use App\Models\Listing;
use App\Models\Search;
use App\Scrapers\Concerns\FetchesPages;
use App\Scrapers\Concerns\StoresCoordinates;
use App\Scrapers\Contracts\EnrichesListings;
use App\Scrapers\Contracts\PortalScraper;
use App\Scrapers\Data\ListingData;
use App\Scrapers\Exceptions\ScraperException;
use App\Scrapers\Support\TextParser;
use App\Services\Geo\SearchArea;
use Dom\Element;
use Dom\HTMLDocument;

/**
 * Nieruchomosci-online.pl. Wyszukiwarka szukaj.html z filtrami w adresie, domyślnie od najnowszych.
 * Liczby z JSON w skrypcie (buildCachedProps.record_props), tytuł, zdjęcie i opis z kafelków.
 * Kafelki "searchSupplement" to oferty z innych miejsc, spoza filtrów: pomijamy.
 */
class NieruchomosciOnlineScraper implements EnrichesListings, PortalScraper
{
    use FetchesPages, StoresCoordinates;

    public function __construct(private HttpFetcher $fetcher) {}

    public function name(): string
    {
        return 'nieruchomosci_online';
    }

    public function buildSearchUrl(Search $search, int $page = 1): string
    {
        // Kolejność pól z search.js: wersja, kategoria, transakcja, region, miasto, dzielnica, ulica,
        // odległość, cena, metraż, zdjęcia, prywatne, id, konto, rynek pierwotny, pokoje.
        $fields = [
            '3',
            $search->property_type === PropertyType::House ? 'dom' : 'mieszkanie',
            $search->deal_type === DealType::Rent ? 'wynajem' : 'sprzedaz',
            '',
            rawurlencode($search->city),
            '', '',
            (string) SearchArea::portalRadius($search->radius_km, [5, 10, 15, 20, 25, 50]),
            $this->range($search->price_min, $search->price_max),
            $this->range($search->area_min, $search->area_max),
            '', '', '', '', '',
            $this->range($search->rooms_min, $search->rooms_max),
        ];

        return config('scrapers.nieruchomosci_online.base_url').'/szukaj.html?'.rtrim(implode(',', $fields), ',')
            .($page > 1 ? "&p={$page}" : '');
    }

    public function parse(string $body, Search $search): array
    {
        $props = TextParser::jsonAfter($body, 'buildCachedProps(');

        if ($props === null) {
            throw new ScraperException('Nieruchomości-online: brak danych ofert (buildCachedProps). Strona mogła się zmienić.');
        }

        $records = $props['record_props'] ?? [];
        $document = HTMLDocument::createFromString($body, LIBXML_NOERROR);
        $listings = [];

        foreach ($document->querySelectorAll('div.tile[data-id]') as $tile) {
            $key = $tile->getAttribute('data-id');

            if ($tile->getAttribute('data-pie') === 'searchSupplement' || ! isset($records[$key])) {
                continue;
            }

            $listing = $this->toListingData($tile, $records[$key], $search);

            if ($listing !== null) {
                $listings[] = $listing;
            }
        }

        return ['listings' => $listings, 'total_pages' => max(1, (int) ($props['list_props']['pgpgs'] ?? 1))];
    }

    public function enrich(Listing $listing): bool
    {
        $html = $this->fetcher->get($this->name(), $listing->url);
        $details = $this->parseOffer($html);
        $this->applyCoordinates($listing, TextParser::coordinates($html));

        $listing->description = $details['description'] ?? $listing->description;
        $listing->rent_extra ??= $details['rent_extra'];
        $listing->floor ??= $details['floor'];
        $listing->raw = array_merge($listing->raw ?? [], ['details' => $details['details']]);
        $listing->content_hash = ListingData::hashOf($listing->title, $listing->price, $listing->area, $listing->description);

        return $listing->isDirty() && $listing->save();
    }

    /**
     * @return array{description: ?string, rent_extra: ?int, floor: ?string, details: array<string, mixed>}
     */
    public function parseOffer(string $html): array
    {
        $document = HTMLDocument::createFromString($html, LIBXML_NOERROR);
        $info = [];

        foreach ($document->querySelectorAll('ul.list-h li') as $item) {
            $label = $item->querySelector('strong')?->textContent;
            $value = $item->querySelector('span')?->textContent;

            if ($label && $value) {
                $info[trim(rtrim(trim($label), ':'))] = trim(preg_replace('/\s+/u', ' ', $value));
            }
        }

        $apartment = $this->apartmentJson($html);
        $description = $document->querySelector('div.estate-desc-more p') ?? $document->querySelector('div.estate-desc-less p');
        $floor = $apartment['floorLevel'] ?? (preg_match('/piętro\s+(\d+|parter)/u', $info['Rozkład mieszkania'] ?? '', $match) ? $match[1] : null);

        return [
            'description' => $description ? TextParser::htmlToText($description->innerHTML) : null,
            'rent_extra' => isset($info['Opłaty uzupełniające']) ? TextParser::int($info['Opłaty uzupełniające']) : null,
            'floor' => match ((string) $floor) {
                '' => null,
                '0', 'parter' => 'parter',
                default => (string) $floor,
            },
            'details' => array_filter([
                'build_year' => $apartment['yearBuilt'] ?? null,
                'source' => $info['Źródło'] ?? null,
                'layout' => $info['Rozkład mieszkania'] ?? null,
            ]),
        ];
    }

    private function toListingData(Element $tile, array $record, Search $search): ?ListingData
    {
        $link = $tile->querySelector('h2.name a');
        $url = $link?->getAttribute('href');

        if (! $url || ! preg_match('/(\d+)\.html/', $url, $id)) {
            return null;
        }

        $province = array_map('trim', explode(',', $tile->querySelector('p.province')?->textContent ?? ''));
        $province = array_values(array_filter($province, fn ($part) => $part !== '' && $part !== 'podkarpackie'));
        $floorText = $tile->querySelector('.attributes__box')?->textContent ?? '';
        $floor = preg_match('/Piętro:\s*(parter|-?\d+)/u', preg_replace('/\s+/u', ' ', $floorText), $match) ? $match[1] : null;
        $description = $tile->querySelector('p.desc')?->textContent;
        $district = $record['rlocqtn'] ?? null;
        $city = end($province) ?: $search->city;

        // Oferty deweloperów mają dzielnicę i miasto bez przecinka: "Załęże Rzeszów".
        if ($district && str_starts_with($city, "{$district} ")) {
            $city = trim(substr($city, strlen($district)));
        }

        $image = $tile->querySelector('ul.thumb-slider img')?->getAttribute('src');

        return new ListingData(
            portal: $this->name(),
            externalId: $id[1],
            url: $url,
            title: trim($link->textContent),
            dealType: $search->deal_type,
            price: isset($record['prc']) ? (int) $record['prc'] : null,
            area: isset($record['rsur']) ? (float) $record['rsur'] : null,
            rooms: isset($record['rooms']) ? (int) $record['rooms'] : null,
            floor: $floor === '0' ? 'parter' : $floor,
            // "Biała, Rzeszów" (dzielnica, miasto), "Rzeszów, podkarpackie" albo "Załęże Rzeszów".
            city: $city,
            district: $district,
            street: $record['rlocstsn'] ?? null,
            isPrivateOwner: match ($record['pbh'] ?? null) {
                'priv' => true,
                null => null,
                default => false,
            },
            description: $description ? trim(str_replace("\u{00A0}", ' ', $description)) : null,
            imageUrl: $image,
            raw: array_filter([
                'id' => $id[1],
                'market' => $record['rcmrkt'] ?? null,
                'location_alias' => $tile->getAttribute('data-location-alias'),
            ]),
        );
    }

    private function apartmentJson(string $html): array
    {
        preg_match_all('#<script type="application/ld\+json">(.*?)</script>#s', $html, $scripts);

        foreach ($scripts[1] as $script) {
            $data = json_decode($script, true);

            if (($data['@type'] ?? null) === 'Apartment') {
                return $data;
            }
        }

        return [];
    }

    private function range(?int $min, ?int $max): string
    {
        return $min === null && $max === null ? '' : ($min ?? '').'-'.($max ?? '');
    }
}
