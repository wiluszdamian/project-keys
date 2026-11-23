<?php

use App\Jobs\AnalyzeListingJob;
use App\Models\Listing;
use App\Models\Search;
use App\Services\Geo\SearchArea;
use App\Services\ListingMatcher;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Sleep;

// Rzeszów (rynek), Tajęcina (ok. 9 km), Krosno (ok. 50 km).
function fakeNominatim(): void
{
    Sleep::fake();
    Http::fake(function ($request) {
        $q = $request['q'] ?? '';
        $place = match (true) {
            str_starts_with($q, 'Rzeszów') => [50.0375, 22.0049, 'Rzeszów, województwo podkarpackie, Polska'],
            str_starts_with($q, 'Tajęcina') => [50.1017, 22.0636, 'Tajęcina, gmina Trzebownisko, województwo podkarpackie, Polska'],
            str_starts_with($q, 'Krosno') => [49.6887, 21.7706, 'Krosno, województwo podkarpackie, Polska'],
            default => null,
        };

        return Http::response($place ? [['lat' => (string) $place[0], 'lon' => (string) $place[1], 'display_name' => $place[2]]] : []);
    });
}

it('wybiera promień portalu, który obejmuje promień wyszukiwania', function () {
    expect(SearchArea::portalRadius(10, [5, 10, 15]))->toBe(10)
        ->and(SearchArea::portalRadius(12, [5, 10, 15]))->toBe(15)
        ->and(SearchArea::portalRadius(80, [5, 10, 15]))->toBe(15)
        ->and(SearchArea::portalRadius(null, [5, 10]))->toBeNull();
});

it('sprawdza miejscowość w promieniu', function () {
    fakeNominatim();
    $area = app(SearchArea::class);
    $withRadius = new Search(['city' => 'Rzeszów', 'radius_km' => 10]);
    $cityOnly = new Search(['city' => 'Rzeszów']);

    expect($area->containsPlace($withRadius, 'Rzeszów'))->toBeTrue()
        ->and($area->containsPlace($withRadius, 'Tajęcina'))->toBeTrue()
        ->and($area->containsPlace($withRadius, 'Krosno'))->toBeFalse()
        ->and($area->containsPlace($withRadius, 'Nieznana Wieś'))->toBeFalse()
        ->and($area->containsPlace($cityOnly, 'Tajęcina'))->toBeFalse();

    // Wieś szukana w tym samym województwie co środek.
    Http::assertSent(fn ($request) => ($request['q'] ?? '') === 'Tajęcina, województwo podkarpackie');
});

it('oferta ze współrzędnymi poza promieniem nie pasuje, bez współrzędnych przechodzi', function () {
    fakeNominatim();
    $search = Search::factory()->create(['city' => 'Rzeszów', 'radius_km' => 10]);
    $near = Listing::factory()->make(['lat' => 50.10, 'lng' => 22.06, 'geo_precision' => 'street']);
    $far = Listing::factory()->make(['lat' => 49.69, 'lng' => 21.77, 'geo_precision' => 'portal']);
    $unknown = Listing::factory()->make(['lat' => null, 'lng' => null]);
    $matcher = app(ListingMatcher::class);

    expect($matcher->inArea($search, $near))->toBeTrue()
        ->and($matcher->inArea($search, $far))->toBeFalse()
        ->and($matcher->inArea($search, $unknown))->toBeTrue();
});

it('ocena AI odłącza ofertę, która po geokodowaniu jest poza promieniem', function () {
    fakeNominatim();
    config(['ai.providers.opencode.key' => 'test-key']);
    $search = Search::factory()->create(['city' => 'Rzeszów', 'radius_km' => 10]);
    $listing = Listing::factory()->create(['lat' => 49.69, 'lng' => 21.77, 'geo_precision' => 'portal', 'geocoded_at' => now()]);
    $search->listings()->attach($listing, ['matched_at' => now()]);

    AnalyzeListingJob::dispatchSync($search, $listing);

    expect($search->listings()->count())->toBe(0);
});
