<?php

use App\Enums\DealType;
use App\Models\Search;
use App\Scrapers\Exceptions\ScraperException;
use App\Scrapers\OlxScraper;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Sleep;

function olxFixture(string $name): string
{
    return file_get_contents(base_path("tests/Fixtures/olx/{$name}.json"));
}

function olxSearch(array $attributes = []): Search
{
    return new Search(array_merge(['deal_type' => 'rent', 'property_type' => 'flat', 'city' => 'Rzeszów'], $attributes));
}

beforeEach(function () {
    Sleep::fake();
    $this->scraper = app(OlxScraper::class);
});

it('odczytuje ogłoszenia na wynajem z fixture', function () {
    $result = $this->scraper->parse(olxFixture('search_rent'), olxSearch());

    // 44 pozycje, ale promowane powtarzają się na liście.
    expect($result['listings'])->toHaveCount(41)
        ->and($result['total_pages'])->toBe(4);

    [$first, $second] = $result['listings'];

    expect($first)
        ->externalId->toBe('1090370264')
        ->url->toStartWith('https://www.olx.pl/d/oferta/')
        ->dealType->toBe(DealType::Rent)
        ->price->toBe(2200)
        ->rentExtra->toBe(860)
        ->area->toBe(63.0)
        ->rooms->toBe(3)
        ->floor->toBeNull()
        ->city->toBe('Rzeszów')
        ->district->toBeNull()
        ->isPrivateOwner->toBeTrue()
        ->imageUrl->toContain('800x600')
        ->description->not->toContain('<br')
        ->and($first->raw)->not->toHaveKeys(['user', 'contact', 'photos', 'description']);

    expect($second)
        ->externalId->toBe('1111658572')
        ->price->toBe(2800)
        ->area->toBe(57.0)
        ->rentExtra->toBe(400)
        ->floor->toBe('1');
});

it('odczytuje sprzedaż i ofertę skopiowaną z Otodom', function () {
    [$first, $second] = $this->scraper->parse(olxFixture('search_sale'), olxSearch(['deal_type' => 'sale']))['listings'];

    expect($first)
        ->externalId->toBe('1097316444')
        ->dealType->toBe(DealType::Sale)
        ->price->toBe(739000)
        ->area->toBe(85.0)
        ->rooms->toBe(4)
        ->rentExtra->toBeNull()
        ->and($first->raw['external_url'])->toStartWith('https://www.otodom.pl/pl/oferta/')
        ->and($first->raw['partner']['code'])->toBe('otodom_pl');

    expect($second)->area->toBe(26.4)->rooms->toBe(1)->floor->toBe('2');
});

it('odczytuje dzielnicę i ogłoszenia firm', function () {
    $listings = $this->scraper->parse(olxFixture('search_krakow'), olxSearch(['city' => 'Kraków']))['listings'];

    expect($listings[0])->district->toBe('Prądnik Biały')->city->toBe('Kraków')
        ->and($listings[1])->isPrivateOwner->toBeFalse()->floor->toBe('8');
});

it('rzuca wyjątek dla złej odpowiedzi', function () {
    $this->scraper->parse('<html>captcha</html>', olxSearch());
})->throws(ScraperException::class, 'bez listy');

it('buduje adres endpointu', function (array $attributes, int $page, string $query) {
    Cache::put('olx:location:nieruchomosci,mieszkania,wynajem,rzeszow', ['category_id' => 15, 'region_id' => 17, 'city_id' => 15241]);

    expect($this->scraper->buildSearchUrl(olxSearch($attributes), $page))
        ->toBe('https://www.olx.pl/api/v1/offers/?'.$query);
})->with([
    'bez filtrów' => [[], 1, 'offset=0&limit=40&category_id=15&region_id=17&city_id=15241&sort_by=created_at%3Adesc'],
    'cena, metraż, pokoje od 3, strona 2' => [
        ['price_min' => 1500, 'price_max' => 3500, 'area_min' => 40, 'area_max' => 80, 'rooms_min' => 3],
        2,
        'offset=40&limit=40&category_id=15&region_id=17&city_id=15241&sort_by=created_at%3Adesc&filter_float_price%3Afrom=1500&filter_float_price%3Ato=3500&filter_float_m%3Afrom=40&filter_float_m%3Ato=80&filter_enum_rooms%5B0%5D=three&filter_enum_rooms%5B1%5D=four',
    ],
    'rynek wtórny' => [
        ['market' => 'secondary'],
        1,
        'offset=0&limit=40&category_id=15&region_id=17&city_id=15241&sort_by=created_at%3Adesc&filter_enum_market%5B0%5D=secondary',
    ],
    'dokładnie 2 pokoje' => [
        ['rooms_min' => 2, 'rooms_max' => 2],
        1,
        'offset=0&limit=40&category_id=15&region_id=17&city_id=15241&sort_by=created_at%3Adesc&filter_enum_rooms%5B0%5D=two',
    ],
]);

it('pobiera identyfikatory miasta i zapamiętuje je', function () {
    Http::fake(['www.olx.pl/api/v1/friendly-links/*' => Http::response(olxFixture('friendly_links_sale'))]);

    $search = olxSearch(['deal_type' => 'sale']);
    $first = $this->scraper->buildSearchUrl($search);
    $this->scraper->buildSearchUrl($search, 2);

    expect($first)->toContain('category_id=14&region_id=17&city_id=15241');
    Http::assertSentCount(1);
    Http::assertSent(fn ($request) => str_ends_with($request->url(), '/friendly-links/query-params/nieruchomosci,mieszkania,sprzedaz,rzeszow/'));
});

it('zgłasza nieznane miasto', function () {
    Http::fake(['www.olx.pl/api/v1/friendly-links/*' => Http::response(['data' => []])]);

    $this->scraper->buildSearchUrl(olxSearch(['city' => 'Pcim Górny']));
})->throws(ScraperException::class, 'Pcim Górny');

it('pobiera do 3 stron z nagłówkiem JSON', function () {
    config(['scrapers.max_pages' => 3]);
    Http::fake([
        'www.olx.pl/api/v1/friendly-links/*' => Http::response(['data' => ['category_id' => 15, 'region_id' => 17, 'city_id' => 15241]]),
        'www.olx.pl/api/v1/offers/*' => Http::response(olxFixture('search_rent')),
    ]);

    $result = $this->scraper->fetchListings(Search::factory()->create(['city' => 'Rzeszów']));

    expect($result->pagesFetched)->toBe(3)->and($result->listings)->toHaveCount(41);
    Http::assertSentCount(4);
    Http::assertSent(fn ($request) => str_contains($request->url(), 'offset=80') && $request->header('Accept') === ['application/json']);
});
