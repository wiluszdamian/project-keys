<?php

use App\Models\Search;
use App\Scrapers\AdresowoScraper;

function adresowoFixture(string $name): string
{
    return file_get_contents(base_path("tests/Fixtures/adresowo/{$name}.html"));
}

beforeEach(function () {
    $this->scraper = app(AdresowoScraper::class);
});

it('buduje adresy z filtrem pokoi i stronami', function () {
    $rooms = Search::factory()->make(['deal_type' => 'sale', 'city' => 'Rzeszów', 'rooms_min' => 3, 'rooms_max' => null]);
    $plain = Search::factory()->make(['deal_type' => 'rent', 'city' => 'Boguchwała', 'rooms_min' => null, 'rooms_max' => null]);

    expect($this->scraper->buildSearchUrl($rooms))->toBe('https://adresowo.pl/f/mieszkania/rzeszow/fp3p4p5p6')
        ->and($this->scraper->buildSearchUrl($rooms, 2))->toBe('https://adresowo.pl/f/mieszkania/rzeszow/fp3p4p5p6_l2')
        ->and($this->scraper->buildSearchUrl($plain))->toBe('https://adresowo.pl/mieszkania-wynajem/boguchwala-o/')
        ->and($this->scraper->buildSearchUrl($plain, 3))->toBe('https://adresowo.pl/mieszkania-wynajem/boguchwala-o/_l3');
});

it('odczytuje oferty na sprzedaż', function () {
    $result = $this->scraper->parse(adresowoFixture('search_sale'), Search::factory()->make(['deal_type' => 'sale', 'city' => 'Rzeszów']));

    // 493 oferty: 40 na pierwszej stronie, potem po 120.
    expect($result['total_pages'])->toBe(5)
        ->and($result['listings'])->toHaveCount(40);

    [$first, $second] = $result['listings'];

    expect($first)
        ->externalId->toBe('4194120')
        ->url->toBe('https://adresowo.pl/o/mieszkanie-rzeszow-drabinianka-ul-strazacka-3-pokojowe-x9m6y5')
        ->title->toBe('Mieszkanie 3-pokojowe Rzeszów Drabinianka, ul. Strażacka')
        ->price->toBe(729000)
        ->area->toBe(77.0)
        ->rooms->toBe(3)
        ->city->toBe('Rzeszów')
        ->district->toBe('Drabinianka')
        ->street->toBe('ul. Strażacka 54E')
        ->isPrivateOwner->toBeTrue();

    expect($second)
        ->externalId->toBe('4348446')
        ->price->toBe(890000)
        ->area->toBe(66.0)
        ->rooms->toBe(4)
        ->district->toBeNull()
        ->street->toBe('ul. Brzozowska')
        ->imageUrl->toStartWith('https://s2.adresowa.pl/oi/5a/39/');
});

it('odczytuje wynajem i pomija oferty z okolicy', function () {
    $rent = $this->scraper->parse(adresowoFixture('search_rent'), Search::factory()->make(['deal_type' => 'rent', 'city' => 'Rzeszów']));
    expect($rent['listings'][0])->externalId->toBe('4300960')->price->toBe(2200)->district->toBe('Śródmieście')->street->toBe('ul. Hetmańska 23');

    $village = $this->scraper->parse(adresowoFixture('search_village'), Search::factory()->make(['deal_type' => 'sale', 'city' => 'Tajęcina']));
    expect($village['listings'])->toHaveCount(2)
        ->and($village['total_pages'])->toBe(1)
        ->and($village['listings'][0])->externalId->toBe('4314500')->price->toBe(469000)->city->toBe('Tajęcina')->street->toBeNull()->isPrivateOwner->toBeFalse();
});

it('odczytuje stronę oferty', function () {
    $private = $this->scraper->parseOffer(adresowoFixture('offer'));
    $agent = $this->scraper->parseOffer(adresowoFixture('offer_agent'));

    expect($private)->floor->toBe('3')->is_private_owner->toBeTrue()
        ->and($private['description'])->toStartWith('Mieszkanie z antresolą - os. Drabinianka')
        ->and($private['description'])->toContain('Niski czynsz: około 550 zł')
        ->and($agent)->floor->toBe('parter')->is_private_owner->toBeFalse();
});
