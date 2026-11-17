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
        ->expectsOutputToContain('Stron: 1, ogłoszeń: 37')
        ->assertSuccessful();

    expect(Listing::count())->toBe(0);
    Http::assertSentCount(1);
});
