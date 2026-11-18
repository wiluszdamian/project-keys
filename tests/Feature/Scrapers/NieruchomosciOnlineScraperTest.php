<?php

use App\Models\Search;
use App\Scrapers\NieruchomosciOnlineScraper;

function nolFixture(string $name): string
{
    return file_get_contents(base_path("tests/Fixtures/nieruchomosci_online/{$name}.html"));
}

beforeEach(function () {
    $this->scraper = app(NieruchomosciOnlineScraper::class);
});

it('buduje adres wyszukiwarki z filtrami', function () {
    $search = Search::factory()->make(['deal_type' => 'rent', 'city' => 'Rzeszów', 'price_min' => null, 'price_max' => 3500, 'area_min' => 40, 'area_max' => null, 'rooms_min' => 3, 'rooms_max' => null]);

    expect($this->scraper->buildSearchUrl($search, 2))
        ->toBe('https://www.nieruchomosci-online.pl/szukaj.html?3,mieszkanie,wynajem,,Rzesz%C3%B3w,,,,-3500,40-,,,,,,3-&p=2');

    $sale = Search::factory()->make(['deal_type' => 'sale', 'city' => 'Rudna Mała', 'price_min' => null, 'price_max' => null, 'area_min' => null, 'area_max' => null, 'rooms_min' => null, 'rooms_max' => null]);
    expect($this->scraper->buildSearchUrl($sale))->toBe('https://www.nieruchomosci-online.pl/szukaj.html?3,mieszkanie,sprzedaz,,Rudna%20Ma%C5%82a');
});

it('odczytuje oferty na wynajem', function () {
    $result = $this->scraper->parse(nolFixture('search_rent'), Search::factory()->make(['deal_type' => 'rent', 'city' => 'Rzeszów']));

    expect($result['total_pages'])->toBe(9)
        ->and($result['listings'])->toHaveCount(40);

    [$first, $second] = $result['listings'];

    expect($first)
        ->externalId->toBe('26991334')
        ->url->toBe('https://rzeszow.nieruchomosci-online.pl/mieszkanie,z-oddzielna-kuchnia/26991334.html')
        ->title->toBe('Mieszkanie umeblowane')
        ->price->toBe(2300)
        ->area->toBe(54.0)
        ->rooms->toBe(3)
        ->floor->toBe('10')
        ->city->toBe('Rzeszów')
        ->district->toBeNull()
        ->street->toBe('Sportowa')
        ->isPrivateOwner->toBeFalse()
        ->imageUrl->toBe('https://i.st-nieruchomosci-online.pl/k2p4gxc/mieszkanie-rzeszow-wynajem.jpg')
        ->and($first->description)->toStartWith('Biuro Stoprocent Nieruchomosci oferuje na wynajem 3-pokojowe');

    expect($second)
        ->externalId->toBe('27126897')
        ->price->toBe(2200)
        ->area->toBe(80.0)
        ->district->toBe('Biała')
        ->city->toBe('Rzeszów')
        ->street->toBe('gen. Władysława Sikorskiego 202')
        ->isPrivateOwner->toBeTrue()
        ->floor->toBeNull();
});

it('pomija oferty z innych miejsc doklejone do wyników', function () {
    $result = $this->scraper->parse(nolFixture('search_sale'), Search::factory()->make(['deal_type' => 'sale', 'city' => 'Rudna Mała']));

    expect($result['listings'])->toHaveCount(3)
        ->and(collect($result['listings'])->pluck('city')->unique()->all())->toBe(['Rudna Mała'])
        ->and($result['listings'][0])->externalId->toBe('26533447')->price->toBe(439000)->area->toBe(50.3)->floor->toBe('1')
        ->and($result['listings'][1])->floor->toBe('parter')->street->toBe('166');
});

it('odczytuje stronę ogłoszenia z opłatami', function () {
    $offer = $this->scraper->parseOffer(nolFixture('offer'));

    expect($offer)
        ->rent_extra->toBe(200)
        ->floor->toBe('3')
        ->and($offer['description'])->toStartWith('Mieszkanie na wynajem przy ul. Krajobrazowej w Rzeszowie.')
        ->and($offer['description'])->toContain('CENA: 2200 zł + 200 + media')
        ->and($offer['details']['build_year'])->toBe(1995);
});
