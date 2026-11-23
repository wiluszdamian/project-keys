<?php

use App\Models\Listing;
use App\Models\Search;
use App\Scrapers\StoprocentScraper;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Sleep;

function stoprocentFixture(string $name): string
{
    return file_get_contents(base_path("tests/Fixtures/stoprocent/{$name}"));
}

beforeEach(function () {
    Sleep::fake();
    $this->scraper = app(StoprocentScraper::class);
});

it('czyta tabelę oferty, opis i miejsce z mapy', function () {
    $sale = $this->scraper->parseOffer(stoprocentFixture('offer_sale.html'));
    $rent = $this->scraper->parseOffer(stoprocentFixture('offer_rent.html'));

    expect($sale)
        ->title->toBe('3 pokojowe mieszkanie z dwoma balkonami-55m2!')
        ->type->toBe('Mieszkania')
        ->deal->toBe('Sprzedaż')
        ->market->toBe('pierwotny')
        ->price->toBe(399000)
        ->area->toBe(54.79)
        ->rooms->toBe(3)
        ->floor->toBe('1')
        ->places->toBe(['Rzeszowski', 'Krasne'])
        ->image->toStartWith('https://img.asariweb.pl/')
        ->and($sale['lat'])->toBe(50.0561831784216)
        ->and($rent)->deal->toBe('Wynajem')->price->toBe(2800)->places->toBe(['Rzeszów'])
        ->and($rent['description'])->toContain('ul. Zelwerowicza');
});

it('bierze nowe oferty z API, pobiera stronę raz i filtruje transakcję oraz miejscowość', function () {
    $items = json_decode(stoprocentFixture('api.json'), true);
    // Pierwsza oferta w fixture to lokal, który scraper pomija po tytule: tu udają mieszkania.
    $items[0]['title']['rendered'] = '3 pokojowe mieszkanie z dwoma balkonami';
    $items[1]['title']['rendered'] = '3 pokoje na wynajem';
    $first = $items[0]['link'];
    Http::fake([
        'stoprocent-nieruchomosci.pl/wp-json/*' => Http::response(json_encode([$items[0], $items[1]])),
        $first => Http::response(stoprocentFixture('offer_sale.html')),
        '*' => Http::response(stoprocentFixture('offer_rent.html')),
    ]);

    $krasne = Search::factory()->make(['deal_type' => 'sale', 'city' => 'Krasne', 'property_type' => 'flat']);
    $result = $this->scraper->fetchListings($krasne);

    expect($result->listings)->toHaveCount(1)
        ->and($result->listings[0])->externalId->toBe((string) $items[0]['id'])->price->toBe(399000)->city->toBe('Krasne')
        ->and($result->listings[0]->raw)->toMatchArray(['market' => 'pierwotny', 'places' => ['Rzeszowski', 'Krasne']]);

    // Drugi przebieg: strony ogłoszeń z pamięci, tylko zapytanie do API.
    $rentRzeszow = Search::factory()->make(['deal_type' => 'rent', 'city' => 'Rzeszów', 'property_type' => 'flat']);
    expect($this->scraper->fetchListings($rentRzeszow)->listings)->toHaveCount(1);
    Http::assertSentCount(4);
});

it('pobiera najwyżej limit nowych stron na przebieg', function () {
    config(['scrapers.stoprocent.max_new_offers' => 1]);
    Http::fake([
        'stoprocent-nieruchomosci.pl/wp-json/*' => Http::response(stoprocentFixture('api.json')),
        '*' => Http::response(stoprocentFixture('offer_rent.html')),
    ]);

    $this->scraper->fetchListings(Search::factory()->make(['deal_type' => 'rent', 'city' => 'Rzeszów']));

    Http::assertSentCount(2);
});

it('enrich ustawia pinezkę z danych oferty bez pobierania strony', function () {
    Http::fake();
    $listing = Listing::factory()->create(['portal' => 'stoprocent', 'raw' => ['lat' => 50.05, 'lng' => 22.1]]);

    $this->scraper->enrich($listing);

    expect($listing->fresh())->lat->toBe(50.05)->lng->toBe(22.1)->geo_precision->toBe('portal');
    Http::assertNothingSent();
});

it('pomija działki i lokale bez pobierania strony ogłoszenia', function () {
    Http::fake([
        'stoprocent-nieruchomosci.pl/wp-json/*' => Http::response(json_encode([
            ['id' => 1, 'modified' => 'x', 'link' => 'https://stoprocent-nieruchomosci.pl/nieruchomosci/dzialka', 'title' => ['rendered' => 'Działka 16 arów | Świlcza']],
            ['id' => 2, 'modified' => 'x', 'link' => 'https://stoprocent-nieruchomosci.pl/nieruchomosci/lokal', 'title' => ['rendered' => 'LOKAL 273 M2 &#8211; CENTRUM']],
        ])),
    ]);

    expect($this->scraper->fetchListings(Search::factory()->make(['deal_type' => 'sale', 'city' => 'Rzeszów']))->listings)->toBe([]);
    Http::assertSentCount(1);
});
