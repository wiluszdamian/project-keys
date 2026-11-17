<?php

namespace App\Scrapers;

use App\Enums\DealType;
use App\Enums\PropertyType;
use App\Models\Search;
use App\Scrapers\Contracts\PortalScraper;
use App\Scrapers\Data\ListingData;
use App\Scrapers\Data\ScrapeResult;
use App\Scrapers\Exceptions\ScraperException;
use Illuminate\Support\Arr;
use Illuminate\Support\Str;

/**
 * Otodom. Dane z JSON w tagu __NEXT_DATA__ na stronie wyników
 * (props.pageProps.data.searchAds.items), 36 ogłoszeń na stronę.
 */
class OtodomScraper implements PortalScraper
{
    private const ROOMS = [1 => 'ONE', 2 => 'TWO', 3 => 'THREE', 4 => 'FOUR', 5 => 'FIVE'];

    private const ROOMS_FROM_PORTAL = [
        'ONE' => 1, 'TWO' => 2, 'THREE' => 3, 'FOUR' => 4, 'FIVE' => 5,
        'SIX' => 6, 'SEVEN' => 7, 'EIGHT' => 8, 'NINE' => 9, 'TEN' => 10,
    ];

    private const FLOORS = [
        'CELLAR' => 'suterena', 'GROUND' => 'parter', 'FIRST' => '1', 'SECOND' => '2', 'THIRD' => '3',
        'FOURTH' => '4', 'FIFTH' => '5', 'SIXTH' => '6', 'SEVENTH' => '7', 'EIGHTH' => '8',
        'NINTH' => '9', 'TENTH' => '10', 'ABOVE_TENTH' => '>10', 'GARRET' => 'poddasze',
    ];

    public function __construct(private HttpFetcher $fetcher) {}

    public function name(): string
    {
        return 'otodom';
    }

    public function buildSearchUrl(Search $search, int $page = 1): string
    {
        $transaction = $search->deal_type === DealType::Rent ? 'wynajem' : 'sprzedaz';
        $estate = $search->property_type === PropertyType::House ? 'dom' : 'mieszkanie';

        $query = array_filter([
            'priceMin' => $search->price_min,
            'priceMax' => $search->price_max,
            'areaMin' => $search->area_min,
            'areaMax' => $search->area_max,
            'roomsNumber' => $this->roomsFilter($search->rooms_min, $search->rooms_max),
            'by' => 'LATEST',
            'direction' => 'DESC',
            'page' => $page > 1 ? $page : null,
        ], fn ($value) => $value !== null);

        return sprintf(
            '%s/pl/wyniki/%s/%s/%s?%s',
            config('scrapers.otodom.base_url'),
            $transaction,
            $estate,
            $this->location($search->city),
            http_build_query($query, encoding_type: PHP_QUERY_RFC3986),
        );
    }

    public function fetchListings(Search $search): ScrapeResult
    {
        $listings = [];
        $pages = 0;

        for ($page = 1; $page <= config('scrapers.max_pages'); $page++) {
            $parsed = $this->parse($this->fetcher->get($this->name(), $this->buildSearchUrl($search, $page)));
            $pages++;

            foreach ($parsed['listings'] as $listing) {
                $listings[$listing->externalId] = $listing;
            }

            if ($parsed['listings'] === [] || $page >= ($parsed['total_pages'] ?? 1)) {
                break;
            }
        }

        return new ScrapeResult(array_values($listings), $pages);
    }

    public function parse(string $html): array
    {
        if (! preg_match('/<script id="__NEXT_DATA__"[^>]*>(.*?)<\/script>/s', $html, $match)) {
            throw new ScraperException('Otodom: brak __NEXT_DATA__ na stronie wyników. Strona mogła się zmienić.');
        }

        $data = json_decode($match[1], true);
        $searchAds = Arr::get($data, 'props.pageProps.data.searchAds');

        if (! is_array($searchAds) || ! isset($searchAds['items'])) {
            throw new ScraperException('Otodom: brak listy ogłoszeń w __NEXT_DATA__. Strona mogła się zmienić.');
        }

        return [
            'listings' => array_map(fn (array $item) => $this->toListingData($item), $searchAds['items']),
            'total_pages' => Arr::get($searchAds, 'pagination.totalPages'),
        ];
    }

    private function toListingData(array $item): ListingData
    {
        $locations = collect(Arr::get($item, 'location.reverseGeocoding.locations', []))
            ->pluck('name', 'locationLevel');
        $street = Arr::get($item, 'location.address.street');

        return new ListingData(
            portal: $this->name(),
            externalId: (string) $item['id'],
            url: config('scrapers.otodom.base_url').'/pl/oferta/'.$item['slug'],
            title: trim($item['title'] ?? ''),
            dealType: ($item['transaction'] ?? null) === 'RENT' ? DealType::Rent : DealType::Sale,
            price: ($item['hidePrice'] ?? false) ? null : $this->money($item['totalPrice'] ?? null),
            rentExtra: $this->money($item['rentPrice'] ?? null),
            area: isset($item['areaInSquareMeters']) ? (float) $item['areaInSquareMeters'] : null,
            rooms: self::ROOMS_FROM_PORTAL[$item['roomsNumber'] ?? ''] ?? null,
            floor: self::FLOORS[$item['floorNumber'] ?? ''] ?? null,
            city: $locations->get('city_or_village'),
            district: $locations->get('district'),
            street: $street ? trim(($street['name'] ?? '').' '.($street['number'] ?? '')) : null,
            isPrivateOwner: $item['isPrivateOwner'] ?? null,
            description: $item['shortDescription'] ?? null,
            imageUrl: Arr::get($item, 'images.0.medium'),
            // Bez listy zdjęć, żeby nie trzymać zbędnych danych.
            raw: Arr::except($item, ['images']),
        );
    }

    private function money(?array $value): ?int
    {
        return isset($value['value']) ? (int) round($value['value']) : null;
    }

    /**
     * Otodom przyjmuje w filtrze tylko ONE do FIVE. Gdy zakres wychodzi poza 5,
     * nie wysyłamy filtra, a pokoje sprawdza ListingMatcher.
     */
    private function roomsFilter(?int $min, ?int $max): ?string
    {
        if ($min === null && $max === null) {
            return null;
        }

        $min ??= 1;

        if ($min > 5 || ($max !== null && $max > 5)) {
            return null;
        }

        $values = array_map(fn ($rooms) => self::ROOMS[$rooms], range($min, $max ?? 5));

        return '['.implode(',', $values).']';
    }

    private function location(string $city): string
    {
        $slug = Str::slug($city);
        $location = config("scrapers.otodom.locations.{$slug}");

        if ($location === null) {
            throw new ScraperException("Otodom: brak lokalizacji dla miasta \"{$city}\". Dodaj ją w config/scrapers.php (otodom.locations.{$slug}).");
        }

        return $location;
    }
}
