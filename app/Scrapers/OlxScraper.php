<?php

namespace App\Scrapers;

use App\Enums\DealType;
use App\Enums\PropertyType;
use App\Models\Search;
use App\Scrapers\Contracts\PortalScraper;
use App\Scrapers\Data\ListingData;
use App\Scrapers\Data\ScrapeResult;
use App\Scrapers\Exceptions\ScraperException;
use App\Services\Geo\SearchArea;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

/**
 * OLX. Ten sam endpoint JSON, z którego korzysta strona wyników:
 * /api/v1/offers z category_id, region_id i city_id. Identyfikatory bierzemy
 * z /api/v1/friendly-links/query-params/{ścieżka kategorii i miasta}.
 */
class OlxScraper implements PortalScraper
{
    private const ROOMS = [1 => 'one', 2 => 'two', 3 => 'three', 4 => 'four'];

    // "four" na OLX to "4 i więcej".
    private const ROOMS_FROM_PORTAL = ['one' => 1, 'two' => 2, 'three' => 3, 'four' => 4];

    private const JSON = ['Accept' => 'application/json'];

    public function __construct(private HttpFetcher $fetcher) {}

    public function name(): string
    {
        return 'olx';
    }

    public function buildSearchUrl(Search $search, int $page = 1): string
    {
        $ids = $this->locationIds($search);
        $perPage = config('scrapers.olx.per_page');

        $query = array_filter([
            'offset' => ($page - 1) * $perPage,
            'limit' => $perPage,
            'category_id' => $ids['category_id'],
            'region_id' => $ids['region_id'],
            'city_id' => $ids['city_id'],
            'sort_by' => 'created_at:desc',
            'filter_float_price:from' => $search->price_min,
            'filter_float_price:to' => $search->price_max,
            'filter_float_m:from' => $search->area_min,
            'filter_float_m:to' => $search->area_max,
            'filter_enum_rooms' => $this->roomsFilter($search->rooms_min, $search->rooms_max),
            'filter_enum_market' => $search->market ? [$search->market] : null,
            'distance' => SearchArea::portalRadius($search->radius_km, [2, 5, 10, 15, 30, 50, 75, 100]),
        ], fn ($value) => $value !== null);

        return config('scrapers.olx.base_url').'/api/v1/offers/?'.http_build_query($query, encoding_type: PHP_QUERY_RFC3986);
    }

    public function fetchListings(Search $search): ScrapeResult
    {
        $listings = [];
        $pages = 0;

        for ($page = 1; $page <= config('scrapers.max_pages'); $page++) {
            $body = $this->fetcher->get($this->name(), $this->buildSearchUrl($search, $page), self::JSON);
            $parsed = $this->parse($body, $search);
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

    public function parse(string $body, Search $search): array
    {
        $data = json_decode($body, true);

        if (! is_array($data) || ! isset($data['data']) || ! is_array($data['data'])) {
            throw new ScraperException('OLX: odpowiedź bez listy ogłoszeń. Endpoint mógł się zmienić.');
        }

        $listings = [];

        // Promowane ogłoszenia potrafią wystąpić na liście dwa razy.
        foreach ($data['data'] as $item) {
            $listings[(string) $item['id']] = $this->toListingData($item, $search->deal_type);
        }

        $total = Arr::get($data, 'metadata.total_elements');

        return [
            'listings' => array_values($listings),
            'total_pages' => $total === null ? null : (int) ceil($total / config('scrapers.olx.per_page')),
        ];
    }

    private function toListingData(array $item, DealType $dealType): ListingData
    {
        $params = collect($item['params'] ?? [])->keyBy('key')->map(fn ($param) => $param['value'] ?? null);
        $photo = Arr::get($item, 'photos.0.link');

        return new ListingData(
            portal: $this->name(),
            externalId: (string) $item['id'],
            url: $item['url'],
            title: trim($item['title'] ?? ''),
            dealType: $dealType,
            price: $this->number(Arr::get($params, 'price.value')),
            rentExtra: $this->number(Arr::get($params, 'rent.key')),
            area: is_numeric(Arr::get($params, 'm.key')) ? (float) Arr::get($params, 'm.key') : null,
            rooms: self::ROOMS_FROM_PORTAL[Arr::get($params, 'rooms.key', '')] ?? null,
            floor: $this->floor(Arr::get($params, 'floor_select')),
            city: Arr::get($item, 'location.city.name'),
            district: Arr::get($item, 'location.district.name'),
            street: null,
            isPrivateOwner: isset($item['business']) ? ! $item['business'] : null,
            description: $this->plainText($item['description'] ?? null),
            imageUrl: $photo ? str_replace('{width}x{height}', '800x600', $photo) : null,
            // Bez danych ogłoszeniodawcy i zdjęć. Zostaje external_url (oferty z Otodom).
            raw: Arr::except($item, ['user', 'contact', 'photos', 'description', 'shop', 'safedeal', 'delivery']),
        );
    }

    /**
     * Identyfikatory kategorii, regionu i miasta. Zapamiętane na 30 dni.
     *
     * @return array{category_id: int, region_id: int, city_id: int}
     */
    private function locationIds(Search $search): array
    {
        $category = $search->property_type === PropertyType::House ? 'domy' : 'mieszkania';
        $deal = $search->deal_type === DealType::Rent ? 'wynajem' : 'sprzedaz';
        $path = implode(',', ['nieruchomosci', $category, $deal, Str::slug($search->city)]);

        return Cache::remember("olx:location:{$path}", now()->addDays(30), function () use ($path, $search) {
            $body = $this->fetcher->get(
                $this->name(),
                config('scrapers.olx.base_url')."/api/v1/friendly-links/query-params/{$path}/",
                self::JSON,
            );

            $ids = Arr::only(json_decode($body, true)['data'] ?? [], ['category_id', 'region_id', 'city_id']);

            if (count($ids) !== 3) {
                throw new ScraperException("OLX: nie znam miasta \"{$search->city}\" (ścieżka {$path}).");
            }

            return array_map('intval', $ids);
        });
    }

    /**
     * OLX ma wartości one, two, three i four (4 i więcej).
     *
     * @return list<string>|null
     */
    private function roomsFilter(?int $min, ?int $max): ?array
    {
        if ($min === null && $max === null) {
            return null;
        }

        $from = min(max($min ?? 1, 1), 4);
        $to = min($max ?? 4, 4);

        return $from > $to ? null : array_map(fn ($rooms) => self::ROOMS[$rooms], range($from, $to));
    }

    private function floor(?array $value): ?string
    {
        $key = $value['key'] ?? null;

        return match (true) {
            $key === null => null,
            $key === 'floor_0' => 'parter',
            $key === 'floor_11' => '>10',
            (bool) preg_match('/^floor_(\d+)$/', $key, $match) => $match[1],
            default => isset($value['label']) ? mb_strtolower($value['label']) : null,
        };
    }

    private function number(mixed $value): ?int
    {
        return is_numeric($value) ? (int) round((float) $value) : null;
    }

    private function plainText(?string $html): ?string
    {
        if ($html === null) {
            return null;
        }

        $text = html_entity_decode(strip_tags(preg_replace('/<br\s*\/?>/i', "\n", $html)), ENT_QUOTES | ENT_HTML5);

        return trim(preg_replace("/\n{3,}/", "\n\n", preg_replace('/[ \t]+\n/', "\n", $text)));
    }
}
