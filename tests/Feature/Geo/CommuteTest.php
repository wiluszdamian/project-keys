<?php

use App\Jobs\RecomputeCommutesJob;
use App\Models\CommutePlace;
use App\Models\Listing;
use App\Models\ListingCommute;
use App\Services\Geo\CommuteCalculator;
use App\Services\Geo\ListingLocator;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Sleep;

beforeEach(function () {
    Sleep::fake();
});

function osrmTable(array $durations, array $distances): array
{
    return ['code' => 'Ok', 'durations' => $durations, 'distances' => $distances];
}

it('szuka współrzędnych ulicy w OpenStreetMap i zapamiętuje wynik', function () {
    Http::fake(['nominatim.openstreetmap.org/*' => Http::response([['lat' => '50.0300', 'lon' => '22.0100', 'display_name' => 'Dukielska, Rzeszów']])]);
    $listing = Listing::factory()->create(['city' => 'Rzeszów', 'district' => 'Projektant', 'street' => 'ul. Dukielska', 'lat' => null, 'lng' => null]);
    $twin = Listing::factory()->create(['city' => 'Rzeszów', 'street' => 'ul. Dukielska', 'lat' => null, 'lng' => null]);

    expect(app(ListingLocator::class)->locate($listing))->toBeTrue()
        ->and($listing->fresh())->lat->toBe(50.03)->lng->toBe(22.01)->geo_precision->toBe('street');

    app(ListingLocator::class)->locate($twin);

    Http::assertSentCount(1);
    Http::assertSent(fn ($request) => str_contains(urldecode($request->url()), 'q=Dukielska, Rzeszów, Polska'));
});

it('przybliżonej pinezki OLX nie bierze za dokładną, gdy da się znaleźć dzielnicę', function () {
    Http::fake(['nominatim.openstreetmap.org/*' => Http::response([['lat' => '50.05', 'lon' => '21.97', 'display_name' => 'Nowe Miasto']])]);
    $approx = Listing::factory()->create(['portal' => 'olx', 'city' => 'Rzeszów', 'district' => 'Nowe Miasto', 'street' => null, 'lat' => null, 'lng' => null, 'raw' => ['map' => ['lat' => 50.04, 'lon' => 22.0, 'show_detailed' => false]]]);
    $exact = Listing::factory()->create(['portal' => 'olx', 'city' => 'Rzeszów', 'lat' => null, 'lng' => null, 'raw' => ['map' => ['lat' => 50.011, 'lon' => 21.98, 'show_detailed' => true]]]);

    app(ListingLocator::class)->locate($approx);
    app(ListingLocator::class)->locate($exact);

    expect($approx->fresh())->lat->toBe(50.05)->geo_precision->toBe('district')
        ->and($exact->fresh())->lat->toBe(50.011)->geo_precision->toBe('portal');
});

it('liczy dojazdy autem i rowerem przez OSRM i opisuje je', function () {
    Http::fake([
        'osrm-car:5000/*' => Http::response(osrmTable([[480.0, 900.0]], [[3200.0, 6100.0]])),
        'osrm-bike:5000/*' => Http::response(osrmTable([[840.0, 1500.0]], [[3000.0, 5800.0]])),
    ]);
    Queue::fake();
    $mine = CommutePlace::factory()->create(['name' => 'Praca (ja)', 'max_minutes' => 20]);
    $wife = CommutePlace::factory()->create(['name' => 'Praca (żona)', 'lat' => 50.04, 'lng' => 22.0]);
    $listing = Listing::factory()->create(['lat' => 50.03, 'lng' => 22.01]);

    $saved = app(CommuteCalculator::class)->forListings(collect([$listing]));

    expect($saved)->toBe(4)
        ->and(ListingCommute::where('commute_place_id', $wife->id)->where('mode', 'car')->value('minutes'))->toBe(15)
        ->and(app(CommuteCalculator::class)->describe($listing))->toBe([
            'Praca (ja): 8 min autem (3.2 km), 14 min rowerem (akceptowalnie do 20 min)',
            'Praca (żona): 15 min autem (6.1 km), 25 min rowerem',
        ]);

    Http::assertSent(fn ($request) => str_starts_with($request->url(), 'http://osrm-car:5000/table/v1/car/22.010000,50.030000;'));
});

it('API dodaje miejsce dojazdu, szuka adresu i przelicza dojazdy', function () {
    Http::fake(['nominatim.openstreetmap.org/*' => Http::response([['lat' => '50.0231', 'lon' => '22.0127', 'display_name' => 'Rejtana 36']])]);
    Queue::fake();
    actingAsOwner();

    $this->postJson('/api/commute-places', ['name' => 'Praca (ja)', 'address' => 'ul. Rejtana 36, 35-310 Rzeszów', 'max_minutes' => 20])
        ->assertCreated()
        ->assertJsonPath('data.lat', 50.0231)
        ->assertJsonPath('data.located', true);

    Queue::assertPushed(RecomputeCommutesJob::class);

    $id = CommutePlace::sole()->id;
    $this->patchJson("/api/commute-places/{$id}", ['lat' => 50.03, 'lng' => 22.02])->assertOk()->assertJsonPath('data.lng', 22.02);
    $this->getJson('/api/commute-places')->assertOk()->assertJsonCount(1, 'data');
    $this->deleteJson("/api/commute-places/{$id}")->assertNoContent();
});

it('dojazdy są w danych oferty w API', function () {
    Queue::fake();
    actingAsOwner();
    $place = CommutePlace::factory()->create(['name' => 'Praca']);
    $listing = Listing::factory()->create(['lat' => 50.03, 'lng' => 22.01]);
    ListingCommute::create(['listing_id' => $listing->id, 'commute_place_id' => $place->id, 'mode' => 'car', 'minutes' => 9, 'km' => 3.4]);

    $this->getJson("/api/listings/{$listing->id}")
        ->assertOk()
        ->assertJsonPath('data.commutes.0', ['place_id' => $place->id, 'place' => 'Praca', 'mode' => 'car', 'minutes' => 9, 'km' => 3.4]);
});

it('upraszcza adres z ul. i kodem pocztowym, gdy pełny nie daje wyniku', function () {
    expect(\App\Services\Geo\Geocoder::simplify('ul. Rejtana 36, 35-310 Rzeszów, Polska'))->toBe('Rejtana 36, Rzeszów, Polska');

    Http::fake(['nominatim.openstreetmap.org/*' => Http::sequence()->push([])->push([['lat' => '50.02059', 'lon' => '22.01572', 'display_name' => 'Rejtana 36']])]);

    expect(app(\App\Services\Geo\Geocoder::class)->geocode('ul. Rejtana 36, 35-310 Rzeszów, Polska'))->toMatchArray(['lat' => 50.02059, 'lng' => 22.01572]);
});
