<?php

namespace App\Scrapers;

use App\Enums\DealType;
use App\Enums\PropertyType;
use App\Models\Listing;
use App\Models\RcnTransaction;
use App\Models\Search;
use App\Scrapers\Concerns\StoresCoordinates;
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
 * Rzeszowskie Nieruchomości (rzeszowskie-nieruchomosci.pl), biuro z Rzeszowa. Zwykły HTML (system Esti).
 *
 * Osobne kategorie: rynek wtórny, nowe (rynek pierwotny) i wynajem, od najnowszych, 12 ofert na stronę.
 * Lista ma miejscowość, ulicę, cenę, metraż i pokoje. Miejscowości nie da się filtrować w adresie,
 * więc sprawdzamy ją po pobraniu. Opis, piętro i pinezka są na stronie ogłoszenia.
 */
class RzeszowskieNieruchomosciScraper implements EnrichesListings, PortalScraper
{
    use StoresCoordinates;

    // Kategoria w adresie => rynek.
    private const SALE_CATEGORIES = [
        'rynek-wtorny' => RcnTransaction::SECONDARY,
        'nowe-rynek-pierwotny' => RcnTransaction::PRIMARY,
    ];

    public function __construct(private HttpFetcher $fetcher, private SearchArea $area) {}

    public function name(): string
    {
        return 'rzeszowskie';
    }

    public function buildSearchUrl(Search $search, int $page = 1): string
    {
        return $this->categoryUrl($search, array_key_first($this->categories($search)), $page);
    }

    public function fetchListings(Search $search): ScrapeResult
    {
        $listings = [];
        $pages = 0;

        foreach ($this->categories($search) as $category => $market) {
            for ($page = 1; $page <= config('scrapers.max_pages'); $page++) {
                $parsed = $this->parse($this->fetcher->get($this->name(), $this->categoryUrl($search, $category, $page)), $search);
                $pages++;

                foreach ($parsed['listings'] as $listing) {
                    $listings[$listing->externalId] ??= $market ? $listing->with(raw: $listing->raw + ['market' => $market]) : $listing;
                }

                if ($parsed['count'] === 0 || $page >= ($parsed['total_pages'] ?? 1)) {
                    break;
                }
            }
        }

        return new ScrapeResult(array_values($listings), $pages);
    }

    /**
     * Strona kategorii. Zwraca tylko oferty z miejscowości wyszukiwania.
     *
     * @return array{listings: list<ListingData>, total_pages: int|null, count: int}
     */
    public function parse(string $body, Search $search): array
    {
        $document = HTMLDocument::createFromString($body, LIBXML_NOERROR);
        $cards = $document->querySelectorAll('article.offer');

        if ($cards->length === 0 && ! str_contains($body, 'announcements')) {
            throw new ScraperException('Rzeszowskie Nieruchomości: brak listy ofert. Strona mogła się zmienić.');
        }

        $listings = [];

        foreach ($cards as $card) {
            $listing = $this->toListingData($card, $search);

            if ($listing !== null) {
                $listings[] = $listing;
            }
        }

        preg_match_all('#/strona,(\d+)/#', $body, $pages);

        return [
            'listings' => $listings,
            'total_pages' => max([1, ...array_map('intval', $pages[1])]),
            'count' => $cards->length,
        ];
    }

    public function enrich(Listing $listing): bool
    {
        $html = $this->fetcher->get($this->name(), $listing->url);
        $details = $this->parseOffer($html);
        $this->applyCoordinates($listing, $details['coordinates']);

        $listing->description = $details['description'] ?? $listing->description;
        $listing->floor ??= $details['floor'];
        $listing->street ??= $details['street'];
        $listing->raw = array_merge($listing->raw ?? [], ['details' => $details['details']]);
        $listing->content_hash = ListingData::hashOf($listing->title, $listing->price, $listing->area, $listing->description);

        return $listing->isDirty() && $listing->save();
    }

    /**
     * Strona ogłoszenia: opis, pola "Szczegóły" i "Pomieszczenia", pinezka z mapy Leaflet.
     *
     * @return array{description: ?string, floor: ?string, street: ?string, coordinates: array{0: float, 1: float}|null, details: array<string, string>}
     */
    public function parseOffer(string $html): array
    {
        $document = HTMLDocument::createFromString($html, LIBXML_NOERROR);
        $fields = [];

        foreach ($document->querySelectorAll('ul.text__ul li') as $item) {
            $spans = $item->querySelectorAll('span');

            if ($spans->length >= 2) {
                $fields[trim(rtrim(trim($spans->item(0)->textContent), ':'))] = trim(preg_replace('/\s+/u', ' ', $spans->item(1)->textContent));
            }
        }

        $description = $document->querySelector('section.text .text-box p');
        $floor = $fields['Piętro'] ?? null;
        $coordinates = null;

        if (preg_match('/L\.marker\(\[\s*(-?\d{1,2}\.\d+)\s*,\s*(-?\d{1,3}\.\d+)\s*\]/', $html, $match)) {
            [$lat, $lng] = [(float) $match[1], (float) $match[2]];
            $coordinates = $lat >= 49 && $lat <= 55 && $lng >= 14 && $lng <= 24.2 ? [$lat, $lng] : null;
        }

        return [
            'description' => $description ? trim(TextParser::htmlToText($description->innerHTML)) ?: null : null,
            'floor' => match ($floor) {
                null, '' => null,
                '0', 'parter' => 'parter',
                default => $floor,
            },
            'street' => $fields['Ulica'] ?? null,
            'coordinates' => $coordinates,
            'details' => array_filter([
                'build_year' => $fields['Rok budowy'] ?? null,
                'building_floors' => $fields['Liczba pięter'] ?? null,
                'offer_number' => $fields['Numer oferty'] ?? null,
            ]),
        ];
    }

    /**
     * @return array<string, string|null> [kategoria w adresie => rynek]
     */
    private function categories(Search $search): array
    {
        if ($search->deal_type === DealType::Rent) {
            return ['wynajem' => null];
        }

        return $search->market
            ? array_filter(self::SALE_CATEGORIES, fn (string $market) => $market === $search->market)
            : self::SALE_CATEGORIES;
    }

    private function categoryUrl(Search $search, string $category, int $page): string
    {
        $type = $search->property_type === PropertyType::House ? 'domy' : 'mieszkania';

        return config('scrapers.rzeszowskie.base_url')."/{$type}/{$category}/".($page > 1 ? "strona,{$page}/" : '');
    }

    private function toListingData(Element $card, Search $search): ?ListingData
    {
        $href = $card->querySelector('a.offer__link')?->getAttribute('href') ?? '';

        if (! preg_match('/,nieruchomosc(\d+)\/?$/', $href, $id)) {
            return null;
        }

        // "<b>Rzeszów</b><br />Technologiczna"
        $place = $card->querySelector('.offer__place');
        $city = trim($place?->querySelector('b')?->textContent ?? '');
        $street = trim(str_replace($city, '', $place?->textContent ?? ''));

        if (! $this->area->containsPlace($search, $city)) {
            return null;
        }

        $details = preg_replace('/\s+/u', ' ', $card->querySelector('.offer__details')?->textContent ?? '');
        $image = $card->querySelector('img')?->getAttribute('src');
        $title = $this->titleFromUrl($href);

        return new ListingData(
            portal: $this->name(),
            externalId: $id[1],
            url: config('scrapers.rzeszowskie.base_url').$href,
            title: $title,
            dealType: $search->deal_type,
            price: TextParser::int($card->querySelector('.offer__price')?->textContent),
            area: preg_match('/([\d.,]+)\s*m/u', $details, $match) ? TextParser::number($match[1]) : null,
            rooms: preg_match('/(\d+)\s*pok/u', $details, $match) ? (int) $match[1] : null,
            city: $city,
            street: $street !== '' ? $street : null,
            isPrivateOwner: false,
            imageUrl: $image ? config('scrapers.rzeszowskie.base_url').$image : null,
            raw: ['id' => $id[1]],
        );
    }

    /**
     * Lista nie ma tytułu, tylko adres z tytułu: "/mieszkania/rynek-wtorny/gotowe-mieszkanie-dostepne-od-zaraz,nieruchomosc10597/".
     * Prawdziwy tytuł jest na stronie ogłoszenia, ale do listy wystarcza ten.
     */
    private function titleFromUrl(string $href): string
    {
        $slug = basename(preg_replace('/,nieruchomosc\d+\/?$/', '', $href));

        return ucfirst(str_replace('-', ' ', $slug));
    }
}
