<?php

namespace App\Scrapers;

use App\Enums\DealType;
use App\Enums\PropertyType;
use App\Models\Listing;
use App\Models\RcnTransaction;
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
 * Biuro Pierwsze Piętro (1pietro.net) z Rzeszowa. System Esti, jak Rzeszowskie Nieruchomości, inny wygląd.
 *
 * Kategorie /mieszkania/sprzedaz/ i /mieszkania/wynajem/ (domy: /domy/...), od najnowszych, 18 ofert na stronę,
 * strony strona,N/. Lista ma tytuł, miejscowość, ulicę, cenę, metraż i pokoje. Rynek wybiera się tylko formularzem,
 * więc czytamy go z opisu. Opis i pinezka są na stronie ogłoszenia. robots.txt nie ma (przekierowanie na stronę główną).
 */
class PierwszePietroScraper implements EnrichesListings, PortalScraper
{
    use FetchesPages, StoresCoordinates;

    public function __construct(private HttpFetcher $fetcher, private SearchArea $area) {}

    public function name(): string
    {
        return 'pierwsze_pietro';
    }

    public function buildSearchUrl(Search $search, int $page = 1): string
    {
        $type = $search->property_type === PropertyType::House ? 'domy' : 'mieszkania';
        $deal = $search->deal_type === DealType::Rent ? 'wynajem' : 'sprzedaz';

        return config('scrapers.pierwsze_pietro.base_url')."/{$type}/{$deal}/".($page > 1 ? "strona,{$page}/" : '');
    }

    /**
     * @return array{listings: list<ListingData>, total_pages: int|null}
     */
    public function parse(string $body, Search $search): array
    {
        $document = HTMLDocument::createFromString($body, LIBXML_NOERROR);
        $cards = $document->querySelectorAll('article.single-offer');

        if ($cards->length === 0 && ! str_contains($body, 'search-form')) {
            throw new ScraperException('Pierwsze Piętro: brak listy ofert. Strona mogła się zmienić.');
        }

        $listings = [];

        foreach ($cards as $card) {
            $listing = $this->toListingData($card, $search);

            if ($listing !== null) {
                $listings[] = $listing;
            }
        }

        preg_match_all('#/strona,(\d+)/#', $body, $pages);

        return ['listings' => $listings, 'total_pages' => max([1, ...array_map('intval', $pages[1])])];
    }

    public function enrich(Listing $listing): bool
    {
        $html = $this->fetcher->get($this->name(), $listing->url);
        $details = $this->parseOffer($html);
        $this->applyCoordinates($listing, $details['coordinates']);

        $listing->description = $details['description'] ?? $listing->description;
        $listing->floor ??= $details['floor'];
        $listing->raw = array_merge($listing->raw ?? [], array_filter(['market' => $details['market']]), ['details' => $details['details']]);
        $listing->content_hash = ListingData::hashOf($listing->title, $listing->price, $listing->area, $listing->description);

        return $listing->isDirty() && $listing->save();
    }

    /**
     * Strona ogłoszenia: opis po polsku, pinezka z mapy, piętro i rynek z opisu.
     *
     * @return array{description: ?string, floor: ?string, market: ?string, coordinates: array{0: float, 1: float}|null, details: array<string, string>}
     */
    public function parseOffer(string $html): array
    {
        $document = HTMLDocument::createFromString($html, LIBXML_NOERROR);
        $text = $document->querySelector('.lang-description .lang-text');
        $description = $text ? trim(TextParser::htmlToText($text->innerHTML)) ?: null : null;
        $number = $document->querySelector('.offer-description-article-content > p strong')?->textContent;
        $coordinates = null;

        if (preg_match('/L\.marker\(\[\s*(-?\d{1,2}\.\d+)\s*,\s*(-?\d{1,3}\.\d+)\s*\]/', $html, $match)) {
            [$lat, $lng] = [(float) $match[1], (float) $match[2]];
            $coordinates = $lat >= 49 && $lat <= 55 && $lng >= 14 && $lng <= 24.2 ? [$lat, $lng] : null;
        }

        $normalized = TextParser::normalize((string) $description);

        return [
            'description' => $description,
            // "piętro 1 z 4", "parter"
            'floor' => match (true) {
                preg_match('/\bpietro:?\s*(\d{1,2})\b/', $normalized, $floor) === 1 => $floor[1] === '0' ? 'parter' : $floor[1],
                preg_match('/\bparter\b/', $normalized) === 1 => 'parter',
                default => null,
            },
            'market' => match (true) {
                preg_match('/rynek wtorny|rynku wtornego|rynku wtornym/', $normalized) === 1 => RcnTransaction::SECONDARY,
                preg_match('/rynek pierwotny|rynku pierwotnego|rynku pierwotnym/', $normalized) === 1 => RcnTransaction::PRIMARY,
                default => null,
            },
            'coordinates' => $coordinates,
            'details' => array_filter(['offer_number' => $number ? trim($number) : null]),
        ];
    }

    private function toListingData(Element $card, Search $search): ?ListingData
    {
        $href = $card->querySelector('a.single-offer__link')?->getAttribute('href') ?? '';

        if (! preg_match('/,nieruchomosc(\d+)\/?$/', $href, $id)) {
            return null;
        }

        $city = trim($card->querySelector('.single-offer-description--left__header')?->textContent ?? '');

        if (! $this->area->containsPlace($search, $city)) {
            return null;
        }

        $place = trim($card->querySelector('.single-offer-description--left__place')?->textContent ?? '');
        $details = preg_replace('/\s+/u', ' ', $card->querySelector('.single-offer-description--right')?->textContent ?? '');
        $image = $card->querySelector('img');
        $base = config('scrapers.pierwsze_pietro.base_url');

        return new ListingData(
            portal: $this->name(),
            externalId: $id[1],
            url: $base.$href,
            title: trim($image?->getAttribute('alt') ?? '') ?: $city,
            dealType: $search->deal_type,
            price: TextParser::int($card->querySelector('.single-offer-description--left__price')?->textContent),
            // "54.55 zł / m2 38.50 m2": metraż to liczba tuż przed "m2" (cena za m² ma "zł" przed "/ m2").
            area: preg_match('/([\d.,]+)\s*m\s*2/u', $details, $match) ? TextParser::number($match[1]) : null,
            rooms: preg_match('/(\d+)\s*pok/u', $details, $match) ? (int) $match[1] : null,
            city: $city,
            street: $place !== '' ? $place : null,
            isPrivateOwner: false,
            imageUrl: $image?->getAttribute('src') ? $base.$image->getAttribute('src') : null,
            raw: ['id' => $id[1]],
        );
    }
}
