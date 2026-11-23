<?php

namespace App\Scrapers;

use App\Enums\DealType;
use App\Enums\PropertyType;
use App\Models\Listing;
use App\Models\Search;
use App\Scrapers\Concerns\StoresCoordinates;
use App\Scrapers\Contracts\EnrichesListings;
use App\Scrapers\Contracts\PortalScraper;
use App\Scrapers\Data\ListingData;
use App\Scrapers\Data\ScrapeResult;
use App\Scrapers\Exceptions\ScraperException;
use App\Scrapers\Support\TextParser;
use App\Services\Geo\SearchArea;
use Dom\HTMLDocument;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

/**
 * Stoprocent Nieruchomości (stoprocent-nieruchomosci.pl), biuro z Rzeszowa. WordPress z ofertami z Asari.
 *
 * Nowe oferty bierzemy z publicznego API WordPressa (/wp-json/wp/v2/nieruchomosci, od najnowszych),
 * ale API podaje tylko tytuł i adres. Typ, transakcję, cenę, metraż i miejscowość ma dopiero strona
 * ogłoszenia, więc pobieramy ją raz dla każdej oferty (i ponownie po jej zmianie) i pamiętamy 30 dni.
 * Czytamy całe API (wszystkie oferty biura), bo oferta najmu może wisieć tygodniami, a biuro dodaje
 * kilkanaście ofert dziennie wszystkich rodzajów. Działki, lokale i biura pomijamy po tytule.
 * Żeby jeden przebieg nie trwał długo, nowych stron pobieramy najwyżej scrapers.stoprocent.max_new_offers.
 */
class StoprocentScraper implements EnrichesListings, PortalScraper
{
    use StoresCoordinates;

    private const TYPES = [
        'Mieszkania' => PropertyType::Flat,
        'Domy' => PropertyType::House,
    ];

    // Tytuły ofert, które na pewno nie są mieszkaniem ani domem: bez pobierania strony ogłoszenia.
    private const NOT_A_HOME = '/^(dzialk|lokal|magazyn|hala|biur|grunt|teren|budynek uslug|kamienic)|\b(dzialka|dzialki|lokal uslug|lokal uzytk|lokal handl|powierzchnia biurow)/';

    private int $fetchBudget = 0;

    public function __construct(private HttpFetcher $fetcher, private SearchArea $area) {}

    public function name(): string
    {
        return 'stoprocent';
    }

    public function buildSearchUrl(Search $search, int $page = 1): string
    {
        return config('scrapers.stoprocent.base_url').'/wp-json/wp/v2/nieruchomosci?'.http_build_query([
            'per_page' => config('scrapers.stoprocent.per_page'),
            'page' => $page,
            'orderby' => 'date',
            'order' => 'desc',
            '_fields' => 'id,date,modified,link,title',
        ]);
    }

    public function fetchListings(Search $search): ScrapeResult
    {
        $this->fetchBudget = (int) config('scrapers.stoprocent.max_new_offers');
        $listings = [];
        $pages = 0;

        for ($page = 1; $page <= config('scrapers.stoprocent.api_pages'); $page++) {
            $parsed = $this->parse($this->fetcher->get($this->name(), $this->buildSearchUrl($search, $page), ['Accept' => 'application/json']), $search);
            $pages++;

            foreach ($parsed['listings'] as $listing) {
                $listings[$listing->externalId] ??= $listing;
            }

            // Pełna strona (total_pages null): czytamy dalej. Niepełna: to ostatnia.
            if ($parsed['total_pages'] !== null) {
                break;
            }
        }

        return new ScrapeResult(array_values($listings), $pages);
    }

    /**
     * Strona API z listą ofert. Dla każdej oferty dane ze strony ogłoszenia (z pamięci albo pobrane).
     *
     * @return array{listings: list<ListingData>, total_pages: int|null}
     */
    public function parse(string $body, Search $search): array
    {
        $items = json_decode($body, true);

        if (! is_array($items) || ! array_is_list($items)) {
            throw new ScraperException('Stoprocent: API nie zwróciło listy ofert.');
        }

        $listings = [];

        foreach ($items as $item) {
            if (! isset($item['id'], $item['link'])) {
                continue;
            }

            if (preg_match(self::NOT_A_HOME, TextParser::normalize(html_entity_decode($item['title']['rendered'] ?? '', ENT_QUOTES | ENT_HTML5)))) {
                continue;
            }

            $offer = $this->offer($item);
            $listing = $offer ? $this->toListingData($item, $offer, $search) : null;

            if ($listing !== null) {
                $listings[] = $listing;
            }
        }

        // API nie podaje liczby stron w treści (jest w nagłówku), więc czytamy dalej, póki strona jest pełna.
        return ['listings' => $listings, 'total_pages' => count($items) >= (int) config('scrapers.stoprocent.per_page') ? null : 1];
    }

    /**
     * Dane są już pełne z listy. Tu tylko pinezka, bo ListingData nie ma współrzędnych.
     */
    public function enrich(Listing $listing): bool
    {
        $lat = $listing->raw['lat'] ?? null;
        $lng = $listing->raw['lng'] ?? null;

        if (is_numeric($lat) && is_numeric($lng) && $listing->geo_precision !== 'portal') {
            $this->applyCoordinates($listing, [(float) $lat, (float) $lng]);
        }

        return $listing->isDirty() && $listing->save();
    }

    /**
     * Strona ogłoszenia: tabela "Przegląd" (typ, transakcja, rynek, cena, metraż, pokoje, piętro), opis, mapa.
     *
     * @return array<string, mixed>
     */
    public function parseOffer(string $html): array
    {
        $document = HTMLDocument::createFromString($html, LIBXML_NOERROR);
        $fields = [];

        foreach ($document->querySelectorAll('.property_info_element') as $element) {
            $name = trim(rtrim(trim($element->querySelector('.info_name')?->textContent ?? ''), ':'));
            $fields[$name] = trim(preg_replace('/\s+/u', ' ', $element->querySelector('.info_value')?->textContent ?? ''));
        }

        if ($fields === []) {
            throw new ScraperException('Stoprocent: brak tabeli z danymi oferty. Strona mogła się zmienić.');
        }

        // Miejsce z mapy, np. "/Podkarpackie/Rzeszów" albo "/Podkarpackie/Rzeszowski/Krasne".
        $marker = [];
        if (preg_match('/data-map-markers=(\[.*?\])[\s>]/s', $html, $match)) {
            $marker = json_decode(html_entity_decode($match[1], ENT_QUOTES | ENT_HTML5), true)[0] ?? [];
        }

        $places = array_values(array_filter(explode('/', (string) ($marker['popup'] ?? ''))));
        $floor = $fields['Piętro'] ?? null;

        return [
            'title' => trim(html_entity_decode(Str::before($document->querySelector('title')?->textContent ?? '', ' - Strona numer'), ENT_QUOTES | ENT_HTML5)),
            'type' => $fields['Typ nieruchomości'] ?? null,
            'deal' => $fields['Rodzaj transakcji'] ?? null,
            'market' => isset($fields['Rynek']) ? mb_strtolower($fields['Rynek']) : null,
            'price' => isset($fields['Cena']) ? TextParser::int($fields['Cena']) : null,
            'area' => isset($fields['Powierzchnia']) ? TextParser::number($fields['Powierzchnia']) : null,
            'rooms' => isset($fields['Pokoje']) ? TextParser::int($fields['Pokoje']) : null,
            'floor' => match ($floor) {
                null, '' => null,
                '0', 'parter', 'Parter' => 'parter',
                default => $floor,
            },
            'places' => array_slice($places, 1),
            'lat' => $marker['lat'] ?? null,
            'lng' => $marker['lng'] ?? null,
            'description' => $this->description($document),
            'image' => $document->querySelector('meta[property="og:image"]')?->getAttribute('content'),
            'details' => array_filter([
                'build_year' => isset($fields['Rok budowy']) ? trim(str_replace('r.', '', $fields['Rok budowy'])) : null,
                'heating' => $fields['Ogrzewanie'] ?? null,
                'parking' => $fields['Garaż'] ?? null,
                'market' => $fields['Rynek'] ?? null,
            ]),
        ];
    }

    /**
     * Dane oferty ze strony ogłoszenia, z pamięci, gdy oferta się nie zmieniła. Null, gdy skończył się limit pobrań.
     *
     * @return array<string, mixed>|null
     */
    private function offer(array $item): ?array
    {
        $key = "stoprocent:offer:{$item['id']}:".md5((string) ($item['modified'] ?? ''));
        $cached = Cache::get($key);

        if (is_array($cached)) {
            return $cached;
        }

        if ($this->fetchBudget <= 0) {
            return null;
        }

        $this->fetchBudget--;
        $offer = $this->parseOffer($this->fetcher->get($this->name(), $item['link']));
        Cache::put($key, $offer, now()->addDays(30));

        return $offer;
    }

    private function toListingData(array $item, array $offer, Search $search): ?ListingData
    {
        $deal = match (mb_strtolower((string) $offer['deal'])) {
            'sprzedaż' => DealType::Sale,
            'wynajem' => DealType::Rent,
            default => null,
        };

        if ($deal !== $search->deal_type || (self::TYPES[$offer['type']] ?? null) !== $search->property_type) {
            return null;
        }

        // Miejscowość ze ścieżki z mapy (ostatnia część to miasto albo wieś), bez niej miejscowość wyszukiwania w tytule.
        $places = $offer['places'];
        $place = end($places) ?: (TextParser::mentionsPlace($offer['title'], $search->city) ? $search->city : null);

        if (! $this->area->containsPlace($search, $place)) {
            return null;
        }

        return new ListingData(
            portal: $this->name(),
            externalId: (string) $item['id'],
            url: $item['link'],
            title: $offer['title'] ?: trim(html_entity_decode($item['title']['rendered'] ?? '', ENT_QUOTES | ENT_HTML5)),
            dealType: $deal,
            price: $offer['price'],
            area: $offer['area'],
            rooms: $offer['rooms'],
            floor: $offer['floor'],
            city: $place,
            isPrivateOwner: false,
            description: $offer['description'],
            imageUrl: $offer['image'],
            raw: array_filter([
                'id' => $item['id'],
                'market' => $offer['market'],
                'lat' => $offer['lat'],
                'lng' => $offer['lng'],
                'places' => $places,
                'details' => $offer['details'],
            ]),
        );
    }

    private function description(HTMLDocument $document): ?string
    {
        foreach ($document->querySelectorAll('h3') as $heading) {
            if (trim($heading->textContent) === 'OPIS') {
                $text = $heading->parentElement?->querySelector('.brxe-text');

                return $text ? trim(TextParser::htmlToText($text->innerHTML)) ?: null : null;
            }
        }

        return null;
    }
}
