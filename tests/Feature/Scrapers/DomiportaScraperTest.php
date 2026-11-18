<?php

use App\Enums\DealType;
use App\Models\Search;
use App\Scrapers\DomiportaScraper;
use App\Scrapers\Exceptions\ScraperException;

function domiportaFixture(string $name): string
{
    return file_get_contents(base_path("tests/Fixtures/domiporta/{$name}.html"));
}

beforeEach(function () {
    $this->scraper = app(DomiportaScraper::class);
});

it('buduje adres z filtrami i sortowaniem od najnowszych', function () {
    $search = Search::factory()->make(['deal_type' => 'rent', 'city' => 'Rzeszów', 'price_min' => null, 'price_max' => 3500, 'area_min' => 40, 'area_max' => null, 'rooms_min' => 3, 'rooms_max' => null]);

    expect($this->scraper->buildSearchUrl($search, 2))
        ->toBe('https://www.domiporta.pl/mieszkanie/wynajme/podkarpackie/rzeszow?Price.To=3500&Surface.From=40&Rooms.From=3&SortingOrderDirection=SortDateDescending&PageNumber=2');
});

it('bez lokalizacji w mapie zgłasza czytelny błąd', function () {
    $this->scraper->buildSearchUrl(Search::factory()->make(['city' => 'Pcim Dolny']));
})->throws(ScraperException::class, 'Domiporta: brak lokalizacji dla miasta "Pcim Dolny"');

it('odczytuje oferty na wynajem z ld+json i czynsz z kafelka', function () {
    $search = Search::factory()->make(['deal_type' => 'rent', 'city' => 'Rzeszów']);
    $result = $this->scraper->parse(domiportaFixture('search_rent'), $search);

    expect($result['total_pages'])->toBe(2)
        ->and($result['listings'])->toHaveCount(36);

    [$first, $second] = $result['listings'];

    expect($first)
        ->externalId->toBe('156872087')
        ->price->toBe(2690)
        ->rentExtra->toBe(1300)
        ->area->toBe(90.9)
        ->rooms->toBe(3)
        ->city->toBe('Rzeszów')
        ->district->toBe('Zawiszy Czarnego')
        ->street->toBe('Architektów')
        ->dealType->toBe(DealType::Rent)
        ->and($first->raw['seller'])->toBe('homfi');

    expect($second)
        ->externalId->toBe('157012105')
        ->url->toBe('https://www.domiporta.pl/nieruchomosci/wynajme-mieszkanie-trzypokojowe-rzeszow-projektant-dukielska-56m2/157012105')
        ->title->toBe('Mieszkanie trzypokojowe na wynajem: Rzeszów Projektant: Dukielska')
        ->price->toBe(2300)
        ->rentExtra->toBe(450)
        ->area->toBe(56.0)
        ->district->toBe('Projektant')
        ->street->toBe('Dukielska')
        ->isPrivateOwner->toBeFalse()
        ->and($second->raw['date_posted'])->toBe('2026-11-17');
});

it('rozpoznaje oferty deweloperów i nie bierze ofert z promienia 15 km', function () {
    $sale = $this->scraper->parse(domiportaFixture('search_sale'), Search::factory()->make(['deal_type' => 'sale', 'city' => 'Rzeszów']));

    expect($sale['total_pages'])->toBe(14)
        ->and($sale['listings'][0])->externalId->toBe('nowe-675659')->price->toBe(534546)->area->toBe(62.52)->rooms->toBe(3)->street->toBe('Budziwojska 3');

    $radius = $this->scraper->parse(domiportaFixture('search_sale_radius'), Search::factory()->make(['deal_type' => 'sale', 'city' => 'Rudna Mała']));

    expect($radius['listings'])->toHaveCount(13)
        ->and($radius['total_pages'])->toBe(1)
        ->and(collect($radius['listings'])->pluck('city')->unique()->all())->toBe(['Rudna Mała'])
        ->and($radius['listings'][0])->externalId->toBe('154852051')->price->toBe(725000);
});

it('odczytuje stronę ogłoszenia', function () {
    $offer = $this->scraper->parseOffer(domiportaFixture('offer'));

    expect($offer)
        ->rent_extra->toBe(450)
        ->floor->toBe('1')
        ->is_private_owner->toBeFalse()
        ->and($offer['description'])->toStartWith('Oferujemy do wynajęcia ładne mieszkanie ,na osiedlu Projektant')
        ->and($offer['details'])->toMatchArray(['building_floors' => '3', 'build_year' => '2014']);
});
