<?php

use App\Models\Listing;
use App\Models\Search;
use App\Scrapers\JokerHouseScraper;
use App\Services\ListingAvailability;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Sleep;

function jokerHouseFixture(string $name): string
{
    return file_get_contents(base_path("tests/Fixtures/jokerhouse/{$name}.html"));
}

beforeEach(function () {
    $this->scraper = app(JokerHouseScraper::class);
});

it('buduje adresy wyników', function () {
    expect($this->scraper->buildSearchUrl(Search::factory()->make(['deal_type' => 'rent', 'city' => 'Rzeszów'])))
        ->toBe('https://jokerhouse-nieruchomosci.pl/results-listings/?search_category=2&field_5=Na+wynajem')
        ->and($this->scraper->buildSearchUrl(Search::factory()->make(['deal_type' => 'sale', 'city' => 'Rzeszów']), 2))
        ->toBe('https://jokerhouse-nieruchomosci.pl/results-listings/?search_category=2&field_5=Na+sprzeda%C5%BC&wmvc_paged=2');
});

it('odczytuje oferty najmu z listy', function () {
    $result = $this->scraper->parse(jokerHouseFixture('search_rent'), Search::factory()->make(['deal_type' => 'rent', 'city' => 'Rzeszów']));

    expect($result['total_pages'])->toBe(3)
        ->and($result['count'])->toBe(9)
        ->and(count($result['listings']))->toBeGreaterThan(5);

    $listing = collect($result['listings'])->firstWhere('externalId', 'duze-mieszkanie-3-pokojowe-parking-w-garazu');

    expect($listing)
        ->url->toBe('https://jokerhouse-nieruchomosci.pl/wdk-listing/duze-mieszkanie-3-pokojowe-parking-w-garazu/')
        ->title->toBe('Duże mieszkanie 3 pokojowe/ parking w garażu!')
        ->price->toBe(2290)
        ->area->toBe(57.0)
        ->rooms->toBe(3)
        ->city->toBe('Rzeszów')
        ->isPrivateOwner->toBeFalse()
        ->and($listing->imageUrl)->toStartWith('https://jokerhouse-nieruchomosci.pl/wp-content/uploads/');
});

it('pomija oferty archiwalne i spoza miasta', function () {
    $result = $this->scraper->parse(jokerHouseFixture('search_sale'), Search::factory()->make(['deal_type' => 'sale', 'city' => 'Rzeszów', 'radius_km' => null]));
    $titles = collect($result['listings'])->pluck('title');

    expect($result['total_pages'])->toBe(8)
        ->and($titles)->not->toContain(fn (string $title) => str_contains($title, 'ARCHIWALNA'))
        ->and(collect($result['listings'])->pluck('city')->unique()->all())->toBe(['Rzeszów'])
        ->and(collect($result['listings'])->firstWhere('externalId', 'mieszkanie-2-pokojowe-dobry-stan-blok-z-winda'))
        ->price->toBe(415000)->area->toBe(39.4)->rooms->toBe(2);
});

it('czyta stronę ogłoszenia i uzupełnia ofertę', function () {
    Sleep::fake();
    Http::fake(['jokerhouse-nieruchomosci.pl/*' => Http::response(jokerHouseFixture('offer'))]);
    $offer = $this->scraper->parseOffer(jokerHouseFixture('offer'));

    expect($offer['description'])->toContain('ul. Architektów')
        ->and($offer)->floor->toBe('1')->rooms->toBe(3)->rent_extra->toBe(983)->market->toBe('secondary')->archived->toBeFalse()
        ->and($offer['details'])->toMatchArray(['build_year' => '2014', 'post_id' => '14862']);

    $listing = Listing::factory()->create([
        'portal' => 'jokerhouse', 'description' => null, 'floor' => null, 'rooms' => null, 'rent_extra' => null,
        'url' => 'https://jokerhouse-nieruchomosci.pl/wdk-listing/duze-mieszkanie-3-pokojowe-parking-w-garazu/',
    ]);
    $this->scraper->enrich($listing);

    expect($listing->fresh())->floor->toBe('1')->rooms->toBe(3)->rent_extra->toBe(983)->description->toContain('Architektów');
});

it('rozpoznaje ofertę przeniesioną do archiwum', function () {
    Sleep::fake();
    Http::fake(['jokerhouse-nieruchomosci.pl/*' => Http::response(jokerHouseFixture('offer_archived'))]);

    expect($this->scraper->parseOffer(jokerHouseFixture('offer_archived'))['archived'])->toBeTrue();

    $listing = Listing::factory()->create(['portal' => 'jokerhouse', 'url' => 'https://jokerhouse-nieruchomosci.pl/wdk-listing/przytulne-funkcjonalne-mieszkanie-centrum-miasta/']);

    expect(ListingAvailability::classify($listing, ['status' => 200, 'body' => jokerHouseFixture('offer_archived'), 'url' => $listing->url]))->not->toBeNull()
        ->and(ListingAvailability::classify($listing, ['status' => 200, 'body' => jokerHouseFixture('offer'), 'url' => $listing->url]))->toBeNull();
});
