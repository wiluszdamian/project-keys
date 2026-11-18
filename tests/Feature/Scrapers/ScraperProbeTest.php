<?php

use App\Models\Listing;
use App\Models\Search;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Sleep;

it('wypisuje oferty z portalu i nic nie zapisuje', function () {
    Sleep::fake();
    Http::fake(['www.otodom.pl/*' => Http::response(file_get_contents(base_path('tests/Fixtures/otodom/search_rent.html')))]);
    $search = Search::factory()->create(['districts' => ['Czyżyny'], 'price_max' => 4000, 'rooms_min' => 2, 'rooms_max' => 3]);

    $this->artisan('scraper:probe', ['portal' => 'otodom', 'search' => $search->id])
        ->expectsOutputToContain('Dywizjonu 303')
        ->expectsOutputToContain('Stron: 1, ogłoszeń: 36')
        ->assertSuccessful();

    expect(Listing::count())->toBe(0);
    Http::assertSentCount(1);
});

it('działa też dla OLX', function () {
    Sleep::fake();
    Http::fake([
        'www.olx.pl/api/v1/friendly-links/*' => Http::response(['data' => ['category_id' => 15, 'region_id' => 17, 'city_id' => 15241]]),
        'www.olx.pl/api/v1/offers/*' => Http::response(file_get_contents(base_path('tests/Fixtures/olx/search_rent.json'))),
    ]);
    $search = Search::factory()->create(['city' => 'Rzeszów', 'price_min' => null, 'price_max' => 3500, 'area_min' => null, 'area_max' => null, 'rooms_min' => 3, 'rooms_max' => null]);

    $this->artisan('scraper:probe', ['portal' => 'olx', 'search' => $search->id])
        ->expectsOutputToContain('Stron: 1, ogłoszeń: 41')
        ->assertSuccessful();
});
