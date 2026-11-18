<?php

use App\Enums\DealType;
use App\Models\Listing;
use App\Models\Search;
use App\Scrapers\RzeszowiakScraper;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Sleep;

function rzeszowiakFixture(string $name): string
{
    return file_get_contents(base_path("tests/Fixtures/rzeszowiak/{$name}.html"));
}

beforeEach(function () {
    Sleep::fake();
    $this->scraper = app(RzeszowiakScraper::class);
});

it('buduje adres kategorii z ceną, sortowaniem od najnowszych i 35 ofertami na stronę', function () {
    $search = Search::factory()->make(['deal_type' => 'rent', 'city' => 'Rzeszów', 'price_min' => null, 'price_max' => 3500]);

    expect($this->scraper->buildSearchUrl($search, 2))
        ->toBe('https://www.rzeszowiak.pl/Nieruchomosci-Mam-do-wynajecia-2100021355?max=3500&r=mieszkanie');
});

it('odczytuje oferty na wynajem z listy', function () {
    $search = Search::factory()->make(['deal_type' => 'rent', 'city' => 'Rzeszów']);
    $result = $this->scraper->parse(rzeszowiakFixture('search_rent'), $search);

    expect($result['total_pages'])->toBe(14)
        ->and($result['listings'])->not->toBeEmpty();

    $first = $result['listings'][0];

    expect($first)
        ->externalId->toBe('55324105')
        ->url->toBe('https://www.rzeszowiak.pl/mam-do-wynajecia-nowoczesnie-urzadzona-kawalerke-37-m2-55324105')
        ->title->toBe('Nowoczesna kawalerka blisko politechniki')
        ->dealType->toBe(DealType::Rent)
        ->price->toBe(1500)
        ->area->toBe(37.0)
        ->rooms->toBe(1)
        ->city->toBe('Rzeszów')
        ->imageUrl->toStartWith('https://www.rzeszowiak.pl/img/ogl/553/mini/l_55324105_0.jpg')
        ->and($first->description)->toStartWith('Mam do wynajęcia')
        ->and($first->raw['added'])->toBe('2026-11-17 18:20');

    $second = collect($result['listings'])->firstWhere('externalId', '55315273');
    expect($second)->price->toBe(3500)->area->toBe(75.0);
});

it('przy sprzedaży liczy metraż z ceny za m2 i pomija inne miejscowości', function () {
    $search = Search::factory()->make(['deal_type' => 'sale', 'city' => 'Rzeszów']);
    $listings = collect($this->scraper->parse(rzeszowiakFixture('search_sale'), $search)['listings']);

    expect($listings->firstWhere('externalId', '55323228'))
        ->price->toBe(559000)
        ->area->toBe(63.5)
        ->rooms->toBe(4)
        ->and($listings->firstWhere('externalId', '55322884'))->toBeNull(); // Przemyśl
});

it('dla miejscowości pod Rzeszowem bierze tylko ogłoszenia, które ją wymieniają', function () {
    $tyczyn = Search::factory()->make(['deal_type' => 'sale', 'city' => 'Tyczyn']);
    $listings = collect($this->scraper->parse(rzeszowiakFixture('search_sale_agency'), $tyczyn)['listings']);

    expect($listings->pluck('externalId')->all())->toContain('55319942')
        ->and($listings->pluck('externalId')->all())->not->toContain('55319952');
});

it('odczytuje stronę ogłoszenia', function () {
    $offer = $this->scraper->parseOffer(rzeszowiakFixture('offer_sale_agency'));

    expect($offer)
        ->area->toBe(72.5)
        ->rooms->toBe(3)
        ->floor->toBe('4')
        ->city->toBe('Rzeszów')
        ->street->toBe('ul. Architektów 11')
        ->and($offer['description'])->toStartWith('Na sprzedaż 3-pokojowe mieszkanie o powierzchni 72,5 m²')
        ->and($offer['description'])->not->toContain('Â')
        ->and($offer['details'])->toMatchArray(['building' => 'wielka plyta', 'condition' => 'do zamieszkania', 'building_floors' => '8']);

    $rent = $this->scraper->parseOffer(rzeszowiakFixture('offer'));
    expect($rent)->area->toBe(50.0)->city->toBe('Rzeszów')->street->toBe('Dąbrowskiego 85')->czynsz->toBe(2800);
});

it('pobiera kategorie prywatne i agencji, a przy wzbogaceniu nie bierze najmu za czynsz', function () {
    Http::fake([
        'www.rzeszowiak.pl/Nieruchomosci-Mam-do-wynajecia-agencje--*' => Http::response(rzeszowiakFixture('search_rent')),
        'www.rzeszowiak.pl/Nieruchomosci-Mam-do-wynajecia-*' => Http::response(rzeszowiakFixture('search_rent')),
        'www.rzeszowiak.pl/*' => Http::response(rzeszowiakFixture('offer')),
    ]);
    config(['scrapers.max_pages' => 1]);
    $search = Search::factory()->make(['deal_type' => 'rent', 'city' => 'Rzeszów']);

    $result = $this->scraper->fetchListings($search);

    expect($result->pagesFetched)->toBe(2)
        ->and($result->listings[0]->isPrivateOwner)->toBeTrue();

    $listing = Listing::factory()->create(['portal' => 'rzeszowiak', 'deal_type' => 'rent', 'price' => 2800, 'rent_extra' => null, 'url' => 'https://www.rzeszowiak.pl/x-55304016']);
    $this->scraper->enrich($listing);

    expect($listing->fresh())->area->toEqual('50.00')->rent_extra->toBeNull()->street->toBe('Dąbrowskiego 85');
});
