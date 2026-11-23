<?php

use App\Models\Listing;
use App\Models\Search;
use App\Scrapers\RzeszowskieNieruchomosciScraper;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Sleep;

function rzeszowskieFixture(string $name): string
{
    return file_get_contents(base_path("tests/Fixtures/rzeszowskie/{$name}.html"));
}

beforeEach(function () {
    $this->scraper = app(RzeszowskieNieruchomosciScraper::class);
});

it('buduje adresy kategorii', function () {
    $sale = Search::factory()->make(['deal_type' => 'sale', 'city' => 'Rzeszów', 'market' => 'secondary']);
    $rent = Search::factory()->make(['deal_type' => 'rent', 'city' => 'Rzeszów']);

    expect($this->scraper->buildSearchUrl($sale))->toBe('https://rzeszowskie-nieruchomosci.pl/mieszkania/rynek-wtorny/')
        ->and($this->scraper->buildSearchUrl($sale, 2))->toBe('https://rzeszowskie-nieruchomosci.pl/mieszkania/rynek-wtorny/strona,2/')
        ->and($this->scraper->buildSearchUrl($rent))->toBe('https://rzeszowskie-nieruchomosci.pl/mieszkania/wynajem/');
});

it('odczytuje oferty z listy i pomija inne miejscowości', function () {
    $result = $this->scraper->parse(rzeszowskieFixture('search_sale'), Search::factory()->make(['deal_type' => 'sale', 'city' => 'Rzeszów']));

    expect($result['count'])->toBe(12)
        ->and($result['total_pages'])->toBe(11)
        ->and(count($result['listings']))->toBeGreaterThan(5)->toBeLessThanOrEqual(12);

    $listing = collect($result['listings'])->firstWhere('externalId', '10597');

    expect($listing)
        ->url->toBe('https://rzeszowskie-nieruchomosci.pl/mieszkania/rynek-wtorny/gotowe-mieszkanie-dostepne-od-zaraz,nieruchomosc10597/')
        ->title->toBe('Gotowe mieszkanie dostepne od zaraz')
        ->price->toBe(477000)
        ->area->toBe(35.0)
        ->rooms->toBe(2)
        ->city->toBe('Rzeszów')
        ->street->toBe('Technologiczna')
        ->imageUrl->toStartWith('https://rzeszowskie-nieruchomosci.pl/upload/');

    $other = $this->scraper->parse(rzeszowskieFixture('search_sale'), Search::factory()->make(['deal_type' => 'sale', 'city' => 'Krosno']));
    expect($other['listings'])->toBe([]);
});

it('pobiera wtórny rynek z oznaczeniem rynku', function () {
    Sleep::fake();
    config(['scrapers.max_pages' => 1]);
    Http::fake(['rzeszowskie-nieruchomosci.pl/*' => Http::response(rzeszowskieFixture('search_sale'))]);

    $result = $this->scraper->fetchListings(Search::factory()->make(['deal_type' => 'sale', 'city' => 'Rzeszów', 'market' => 'secondary']));

    expect($result->pagesFetched)->toBe(1)
        ->and($result->listings[0]->raw['market'])->toBe('secondary');
    Http::assertSentCount(1);
});

it('czyta stronę ogłoszenia', function () {
    $offer = $this->scraper->parseOffer(rzeszowskieFixture('offer'));

    expect($offer['description'])->toContain('w pełni wykończone i umeblowane')
        ->and($offer)->floor->toBe('9')->street->toBe('Technologiczna')->coordinates->toBe([50.0708553, 21.9608005])
        ->and($offer['details'])->toMatchArray(['build_year' => '2025', 'building_floors' => '12']);
});

it('uzupełnia ofertę danymi ze strony', function () {
    Sleep::fake();
    Http::fake(['rzeszowskie-nieruchomosci.pl/*' => Http::response(rzeszowskieFixture('offer'))]);
    $listing = Listing::factory()->create(['portal' => 'rzeszowskie', 'description' => null, 'floor' => null, 'url' => 'https://rzeszowskie-nieruchomosci.pl/mieszkania/rynek-wtorny/x,nieruchomosc10597/']);

    $this->scraper->enrich($listing);

    expect($listing->fresh())->floor->toBe('9')->geo_precision->toBe('portal')->description->toContain('Technologicznej');
});
