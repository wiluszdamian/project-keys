<?php

use App\Models\Listing;
use App\Models\Search;
use App\Scrapers\PierwszePietroScraper;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Sleep;

function pierwszePietroFixture(string $name): string
{
    return file_get_contents(base_path("tests/Fixtures/pierwsze_pietro/{$name}.html"));
}

beforeEach(function () {
    $this->scraper = app(PierwszePietroScraper::class);
});

it('buduje adresy kategorii', function () {
    expect($this->scraper->buildSearchUrl(Search::factory()->make(['deal_type' => 'rent', 'city' => 'Rzeszów'])))->toBe('https://1pietro.net/mieszkania/wynajem/')
        ->and($this->scraper->buildSearchUrl(Search::factory()->make(['deal_type' => 'sale', 'city' => 'Rzeszów']), 2))->toBe('https://1pietro.net/mieszkania/sprzedaz/strona,2/');
});

it('odczytuje oferty najmu z listy', function () {
    $result = $this->scraper->parse(pierwszePietroFixture('search_rent'), Search::factory()->make(['deal_type' => 'rent', 'city' => 'Rzeszów']));

    expect($result['total_pages'])->toBe(1)
        ->and(count($result['listings']))->toBeGreaterThan(10);

    $listing = collect($result['listings'])->firstWhere('externalId', '4620');

    expect($listing)
        ->url->toBe('https://1pietro.net/mieszkania/wynajem/os-projektant-z-balkonem-dostepne-od-zaraz,nieruchomosc4620/')
        ->title->toBe('os. Projektant, z balkonem, dostępne od zaraz')
        ->price->toBe(1800)
        ->area->toBe(52.0)
        ->rooms->toBe(2)
        ->city->toBe('Rzeszów')
        ->street->toBe('ul. Krajobrazowa');
});

it('lista sprzedaży ma kolejne strony', function () {
    $result = $this->scraper->parse(pierwszePietroFixture('search_sale'), Search::factory()->make(['deal_type' => 'sale', 'city' => 'Rzeszów']));

    expect($result['total_pages'])->toBeGreaterThanOrEqual(3);
});

it('czyta stronę ogłoszenia i uzupełnia ofertę', function () {
    Sleep::fake();
    Http::fake(['1pietro.net/*' => Http::response(pierwszePietroFixture('offer'))]);
    $offer = $this->scraper->parseOffer(pierwszePietroFixture('offer'));

    expect($offer['description'])->toContain('os. Projektant')
        ->and($offer)->floor->toBe('1')->coordinates->toBe([50.0352396, 21.9710861])
        ->and($offer['details']['offer_number'])->toBe('485/7393/OMW');

    $listing = Listing::factory()->create(['portal' => 'pierwsze_pietro', 'description' => null, 'floor' => null, 'url' => 'https://1pietro.net/mieszkania/wynajem/x,nieruchomosc4620/']);
    $this->scraper->enrich($listing);

    expect($listing->fresh())->floor->toBe('1')->geo_precision->toBe('portal')->description->toContain('Krajobrazowej');
});
