<?php

namespace App\Scrapers;

use App\Enums\DealType;
use App\Enums\PropertyType;
use App\Models\Listing;
use App\Models\RcnTransaction;
use App\Models\Search;
use App\Scrapers\Contracts\EnrichesListings;
use App\Scrapers\Contracts\PortalScraper;
use App\Scrapers\Data\ListingData;
use App\Scrapers\Data\ScrapeResult;
use App\Scrapers\Exceptions\ScraperException;
use App\Scrapers\Support\TextParser;
use App\Services\Geo\SearchArea;
use Dom\Element;
use Dom\HTMLDocument;

/**
 * Biuro Joker House Nieruchomości (jokerhouse-nieruchomosci.pl) z Rzeszowa. WordPress z wtyczką WP Directory Kit.
 *
 * Lista: /results-listings/ z kategorią (2 mieszkania, 1 domy) i polem "Sprzedaż / Wynajem", 9 ofert na stronę,
 * strony wmvc_paged=N. Kafelek ma miejscowość, tytuł, metraż i cenę. Pokoje, piętro, opłaty, rynek i opis
 * są na stronie ogłoszenia. API WordPressa podaje tylko tytuł i opis, bez ceny i kategorii.
 * robots.txt blokuje tylko /wp-admin/.
 */
class JokerHouseScraper implements EnrichesListings, PortalScraper
{
    private const CATEGORIES = [
        'apartment' => 2,
        'house' => 1,
    ];

    public function __construct(private HttpFetcher $fetcher, private SearchArea $area) {}

    public function name(): string
    {
        return 'jokerhouse';
    }

    public function buildSearchUrl(Search $search, int $page = 1): string
    {
        $query = [
            'search_category' => self::CATEGORIES[$search->property_type === PropertyType::House ? 'house' : 'apartment'],
            'field_5' => $search->deal_type === DealType::Rent ? 'Na wynajem' : 'Na sprzedaż',
        ];

        if ($page > 1) {
            $query['wmvc_paged'] = $page;
        }

        return config('scrapers.jokerhouse.base_url').'/results-listings/?'.http_build_query($query);
    }

    public function fetchListings(Search $search): ScrapeResult
    {
        $listings = [];
        $pages = 0;

        for ($page = 1; $page <= config('scrapers.max_pages'); $page++) {
            $parsed = $this->parse($this->fetcher->get($this->name(), $this->buildSearchUrl($search, $page)), $search);
            $pages++;

            foreach ($parsed['listings'] as $listing) {
                $listings[$listing->externalId] ??= $listing;
            }

            // Liczymy kafelki, nie oferty po filtrze miejscowości: strona bez ofert z miasta nie kończy wyników.
            if ($parsed['count'] === 0 || $page >= ($parsed['total_pages'] ?? 1)) {
                break;
            }
        }

        return new ScrapeResult(array_values($listings), $pages);
    }

    /**
     * Strona wyników. Zwraca tylko oferty z miejscowości wyszukiwania.
     *
     * @return array{listings: list<ListingData>, total_pages: int|null, count: int}
     */
    public function parse(string $body, Search $search): array
    {
        $document = HTMLDocument::createFromString($body, LIBXML_NOERROR);
        $cards = $document->querySelectorAll('div.wdk-listing-card');

        if ($cards->length === 0 && ! str_contains($body, 'wdk-search-form')) {
            throw new ScraperException('Joker House: brak listy ofert. Strona mogła się zmienić.');
        }

        $listings = [];

        foreach ($cards as $card) {
            $listing = $this->toListingData($card, $search);

            if ($listing !== null) {
                $listings[] = $listing;
            }
        }

        preg_match_all('/wmvc_paged=(\d+)/', $body, $pages);

        return [
            'listings' => $listings,
            'total_pages' => max([1, ...array_map('intval', $pages[1])]),
            'count' => $cards->length,
        ];
    }

    public function enrich(Listing $listing): bool
    {
        $details = $this->parseOffer($this->fetcher->get($this->name(), $listing->url));

        $listing->description = $details['description'] ?? $listing->description;
        $listing->floor ??= $details['floor'];
        $listing->rooms ??= $details['rooms'];
        $listing->rent_extra ??= $details['rent_extra'];
        $listing->raw = array_merge($listing->raw ?? [], array_filter(['market' => $details['market']]), ['details' => $details['details']]);
        $listing->content_hash = ListingData::hashOf($listing->title, $listing->price, $listing->area, $listing->description);

        return $listing->isDirty() && $listing->save();
    }

    /**
     * Strona ogłoszenia: pola z sekcji "Informacje" i opis. Mapy z pinezką nie ma.
     *
     * @return array{description: ?string, floor: ?string, rooms: ?int, rent_extra: ?int, market: ?string, archived: bool, details: array<string, string>}
     */
    public function parseOffer(string $html): array
    {
        $document = HTMLDocument::createFromString($html, LIBXML_NOERROR);
        $fields = [];

        foreach ($document->querySelectorAll('.wdk-listing-fields-section .wdk-col') as $field) {
            $label = trim(rtrim(trim($field->querySelector('.field_label')?->textContent ?? ''), ':'));
            $value = trim(preg_replace('/\s+/u', ' ', $field->querySelector('.field_value .value')?->textContent ?? ''));

            if ($label !== '' && $value !== '') {
                $fields[$label] = $value;
            }
        }

        $description = $document->querySelector('div.wdk-field-value .value');
        $floor = $fields['Piętro'] ?? null;
        preg_match('/postid-(\d+)/', $html, $post);

        return [
            'description' => $description ? trim(TextParser::htmlToText($description->innerHTML)) ?: null : null,
            'floor' => match (true) {
                $floor === null => null,
                in_array(mb_strtolower($floor), ['0', 'parter'], true) => 'parter',
                default => $floor,
            },
            'rooms' => isset($fields['Liczba pokoi']) ? TextParser::int($fields['Liczba pokoi']) : null,
            'rent_extra' => isset($fields['Opłaty eksploatacyjne']) ? TextParser::int($fields['Opłaty eksploatacyjne']) : null,
            'market' => match (TextParser::normalize($fields['Rynek'] ?? '')) {
                'wtorny' => RcnTransaction::SECONDARY,
                'pierwotny' => RcnTransaction::PRIMARY,
                default => null,
            },
            'archived' => ($fields['Sprzedaż / Wynajem'] ?? null) === 'Oferty Archiwalne',
            'details' => array_filter([
                'build_year' => $fields['Rok budowy'] ?? null,
                'condition' => $fields['Stan nieruchomości'] ?? null,
                'bedrooms' => $fields['Liczba sypialni'] ?? null,
                'post_id' => $post[1] ?? null,
            ]),
        ];
    }

    private function toListingData(Element $card, Search $search): ?ListingData
    {
        $link = $card->querySelector('.wdk-title a');
        $href = $link?->getAttribute('href') ?? '';

        if (! preg_match('~/wdk-listing/([^/?#]+)/?$~', $href, $slug)) {
            return null;
        }

        $title = trim(preg_replace('/\s+/u', ' ', $link->textContent));

        // Biuro dopisuje "ARCHIWALNA" do tytułu sprzedanych ofert, zanim przeniesie je do archiwum.
        if (preg_match('/archiwaln/iu', $title)) {
            return null;
        }

        // "Rzeszów" albo "Rzeszów, Rzeszów" (kilka poziomów lokalizacji).
        $city = trim(explode(',', $card->querySelector('.wdk-over-image-top span')?->textContent ?? '')[0]);

        if (! $this->area->containsPlace($search, $city)) {
            return null;
        }

        $area = $card->querySelector('.wdk-field-8 .wdk-rc-field-value')?->textContent;

        return new ListingData(
            portal: $this->name(),
            externalId: $slug[1],
            url: $href,
            title: $title,
            dealType: $search->deal_type,
            price: TextParser::int($card->querySelector('.wdk-price')?->textContent),
            area: $area !== null ? TextParser::number($area) : null,
            rooms: self::roomsFromTitle($title),
            city: $city,
            isPrivateOwner: false,
            imageUrl: $card->querySelector('img.wdk-image')?->getAttribute('src') ?: null,
            raw: ['slug' => $slug[1]],
        );
    }

    /**
     * Kafelek ma liczbę sypialni, nie pokoi. Pokoje z tytułu: "3 pokojowe", "2-pokojowe", "3 pokoje", "kawalerka".
     * Gdy tytuł ich nie podaje, uzupełnia je strona ogłoszenia.
     */
    private static function roomsFromTitle(string $title): ?int
    {
        $normalized = TextParser::normalize($title);

        return match (true) {
            preg_match('/\b(\d)\s*-?\s*pok/', $normalized, $match) === 1 => (int) $match[1],
            str_contains($normalized, 'kawalerk') => 1,
            default => null,
        };
    }
}
