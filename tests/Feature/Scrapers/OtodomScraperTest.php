<?php

use App\Enums\DealType;
use App\Models\Search;
use App\Scrapers\Exceptions\ScraperException;
use App\Scrapers\OtodomScraper;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Sleep;

function otodomFixture(string $name): string
{
    return file_get_contents(base_path("tests/Fixtures/otodom/{$name}.html"));
}

beforeEach(function () {
    Sleep::fake();
    $this->scraper = app(OtodomScraper::class);
});

it('odczytuje ogłoszenia na wynajem z fixture', function () {
    $result = $this->scraper->parse(otodomFixture('search_rent'), new Search);

    expect($result['listings'])->toHaveCount(37)
        ->and($result['total_pages'])->toBe(49);

    [$first, $second, $third] = $result['listings'];

    expect($first)
        ->externalId->toBe('ID4E1RZ')
        ->url->toBe('https://www.otodom.pl/pl/oferta/dywizjonu-303-2-osobne-pokoje-52-m2-eng-ID4E1RZ')
        ->title->toBe('Dywizjonu 303 | 2 osobne pokoje | 52 m2 | ENG')
        ->dealType->toBe(DealType::Rent)
        ->price->toBe(3000)
        ->rentExtra->toBe(1000)
        ->area->toBe(52.0)
        ->rooms->toBe(2)
        ->floor->toBe('4')
        ->city->toBe('Kraków')
        ->district->toBe('Czyżyny')
        ->street->toBe('os. Dywizjonu 303')
        ->isPrivateOwner->toBeFalse()
        ->imageUrl->toStartWith('https://')
        ->and($first->raw)->not->toHaveKey('images')
        ->and($first->pricePerM2())->toBe(58);

    expect($second)
        ->externalId->toBe('ID4E1RR')
        ->price->toBe(3200)
        ->rooms->toBe(3)
        ->floor->toBe('parter')
        ->district->toBe('Prądnik Biały');

    expect($third)
        ->externalId->toBe('ID4E1Px')
        ->street->toBeNull()
        ->isPrivateOwner->toBeTrue();
});

it('odczytuje ogłoszenia na sprzedaż z fixture', function () {
    $listings = $this->scraper->parse(otodomFixture('search_sale'), new Search)['listings'];

    expect($listings)->toHaveCount(37);

    expect($listings[0])
        ->externalId->toBe('ID4E1Uc')
        ->dealType->toBe(DealType::Sale)
        ->price->toBe(903168)
        ->rentExtra->toBeNull()
        ->area->toBe(53.76)
        ->city->toBe('Warszawa')
        ->district->toBe('Włochy');

    expect($listings[1])
        ->price->toBe(3700000)
        ->rentExtra->toBe(1682)
        ->rooms->toBe(4)
        ->floor->toBe('9');
});

it('rzuca wyjątek, gdy strona nie ma __NEXT_DATA__', function () {
    $this->scraper->parse('<html><body>Captcha</body></html>', new Search);
})->throws(ScraperException::class, '__NEXT_DATA__');

it('buduje adres wyszukiwania', function (array $attributes, int $page, string $expected) {
    $search = new Search(array_merge([
        'deal_type' => 'rent',
        'property_type' => 'flat',
        'city' => 'Kraków',
    ], $attributes));

    expect($this->scraper->buildSearchUrl($search, $page))->toBe($expected);
})->with([
    'wszystkie filtry' => [
        ['price_min' => 2000, 'price_max' => 4000, 'area_min' => 35, 'area_max' => 60, 'rooms_min' => 2, 'rooms_max' => 3],
        1,
        'https://www.otodom.pl/pl/wyniki/wynajem/mieszkanie/malopolskie/krakow/krakow/krakow?priceMin=2000&priceMax=4000&areaMin=35&areaMax=60&roomsNumber=%5BTWO%2CTHREE%5D&by=LATEST&direction=DESC',
    ],
    'sprzedaż bez filtrów, strona 2' => [
        ['deal_type' => 'sale', 'city' => 'Warszawa'],
        2,
        'https://www.otodom.pl/pl/wyniki/sprzedaz/mieszkanie/mazowieckie/warszawa/warszawa/warszawa?by=LATEST&direction=DESC&page=2',
    ],
    'pokoje od 3 bez górnej granicy' => [
        ['rooms_min' => 3],
        1,
        'https://www.otodom.pl/pl/wyniki/wynajem/mieszkanie/malopolskie/krakow/krakow/krakow?roomsNumber=%5BTHREE%2CFOUR%2CFIVE%5D&by=LATEST&direction=DESC',
    ],
    'pokoje powyżej 5 bez filtra w adresie' => [
        ['rooms_min' => 2, 'rooms_max' => 7],
        1,
        'https://www.otodom.pl/pl/wyniki/wynajem/mieszkanie/malopolskie/krakow/krakow/krakow?by=LATEST&direction=DESC',
    ],
    'dom we Wrocławiu' => [
        ['property_type' => 'house', 'city' => 'Wrocław'],
        1,
        'https://www.otodom.pl/pl/wyniki/wynajem/dom/dolnoslaskie/wroclaw/wroclaw/wroclaw?by=LATEST&direction=DESC',
    ],
]);

it('rzuca wyjątek dla miasta bez lokalizacji', function () {
    $this->scraper->buildSearchUrl(new Search(['deal_type' => 'rent', 'city' => 'Pcim']));
})->throws(ScraperException::class, 'Pcim');

it('pobiera kilka stron i usuwa powtórki', function () {
    config(['scrapers.max_pages' => 2]);
    Http::fake(['www.otodom.pl/*' => Http::response(otodomFixture('search_rent'))]);

    $result = $this->scraper->fetchListings(Search::factory()->create());

    expect($result->pagesFetched)->toBe(2)
        ->and($result->listings)->toHaveCount(36);

    Http::assertSentCount(2);
    Http::assertSent(fn ($request) => str_contains($request->url(), 'page=2'));
});

it('kończy na ostatniej stronie wyników', function () {
    config(['scrapers.max_pages' => 3]);
    $html = str_replace('"totalPages": 49', '"totalPages": 1', otodomFixture('search_rent'));
    Http::fake(['www.otodom.pl/*' => Http::response($html)]);

    expect($this->scraper->fetchListings(Search::factory()->create())->pagesFetched)->toBe(1);
    Http::assertSentCount(1);
});

it('wyróżniona kopia z tymczasowym id łączy się z oryginałem', function () {
    $listings = collect($this->scraper->parse(otodomFixture('search_rent'), new Search)['listings']);
    $copy = $listings->firstWhere(fn ($listing) => ($listing->raw['id'] ?? null) === 9000062324);

    expect($copy->externalId)->toBe('ID4E1RZ')
        ->and($listings->where('externalId', 'ID4E1RZ'))->toHaveCount(2)
        ->and($listings->pluck('externalId')->unique())->toHaveCount(36);
});
