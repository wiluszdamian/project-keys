<?php

use App\Filament\Resources\Listings\Pages\ViewListing;
use App\Models\Listing;
use App\Models\Poi;
use App\Models\Search;
use App\Models\User;
use App\Services\Geo\Infrastructure;
use App\Services\Geo\PoiImporter;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Sleep;
use Livewire\Livewire;

it('dzieli punkty z OpenStreetMap na kategorie', function (array $tags, array $expected) {
    $rows = app(PoiImporter::class)->rows(['type' => 'node', 'id' => 1, 'lat' => 50.0, 'lon' => 22.0, 'tags' => $tags]);

    expect(array_column($rows, 'category'))->toBe($expected);
})->with([
    'Biedronka' => [['shop' => 'supermarket', 'brand' => 'Biedronka', 'name' => 'Biedronka'], ['shop']],
    'żłobek' => [['amenity' => 'childcare', 'name' => 'Żłobek Miejski nr 3'], ['nursery']],
    'żłobek i przedszkole' => [['amenity' => 'kindergarten', 'name' => 'Żłobek i Przedszkole Bajka'], ['nursery', 'kindergarten']],
    'przedszkole' => [['amenity' => 'kindergarten', 'name' => 'Przedszkole Publiczne nr 5'], ['kindergarten']],
    'podstawówka' => [['amenity' => 'school', 'name' => 'Szkoła Podstawowa nr 12'], ['primary']],
    'liceum' => [['amenity' => 'school', 'name' => 'III Liceum Ogólnokształcące'], ['secondary']],
    'zespół szkół' => [['amenity' => 'school', 'name' => 'Zespół Szkół Technicznych'], ['secondary']],
    'zespół szkolno-przedszkolny' => [['amenity' => 'school', 'name' => 'Zespół Szkolno-Przedszkolny w Krasnem'], ['primary']],
    'po tagu isced' => [['amenity' => 'school', 'name' => 'Szkoła', 'isced:level' => '3'], ['secondary']],
    'szkoła muzyczna pomijana' => [['amenity' => 'school', 'name' => 'Szkoła Muzyczna I stopnia'], []],
    'szkoła jazdy pomijana' => [['amenity' => 'school', 'name' => 'Szkoła Nauki Jazdy'], []],
    'przychodnia z pediatrą po tagu' => [['amenity' => 'clinic', 'name' => 'Przychodnia Zdrowie', 'healthcare:speciality' => 'general;paediatrics'], ['pediatric', 'clinic']],
    'poradnia dziecięca' => [['amenity' => 'doctors', 'name' => 'Poradnia Pediatryczna'], ['pediatric', 'clinic']],
    'przychodnia POZ' => [['amenity' => 'clinic', 'name' => 'NZOZ Przychodnia Rodzinna'], ['clinic']],
    'dentysta pomijany' => [['amenity' => 'clinic', 'name' => 'Gabinet Stomatologiczny'], []],
]);

it('importuje punkty z Overpass dla obszaru wokół wyszukiwań', function () {
    Sleep::fake();
    Search::factory()->create(['city' => 'Rzeszów', 'radius_km' => 10]);
    Http::fake([
        'nominatim.openstreetmap.org/*' => Http::response([['lat' => '50.0375', 'lon' => '22.0049', 'display_name' => 'Rzeszów, województwo podkarpackie, Polska']]),
        'overpass-api.de/*' => Http::response(['elements' => [
            ['type' => 'node', 'id' => 1, 'lat' => 50.04, 'lon' => 22.01, 'tags' => ['shop' => 'supermarket', 'brand' => 'Lidl', 'name' => 'Lidl', 'addr:street' => 'Krakowska', 'addr:housenumber' => '5']],
            ['type' => 'way', 'id' => 2, 'center' => ['lat' => 50.03, 'lon' => 22.0], 'tags' => ['amenity' => 'school', 'name' => 'Szkoła Podstawowa nr 1']],
        ]]),
    ]);

    $result = app(PoiImporter::class)->import();

    expect($result['total'])->toBe(2)
        ->and(Poi::where('category', 'shop')->sole())->brand->toBe('Lidl')->address->toBe('Krakowska 5')
        ->and($result['bbox'][0])->toBeLessThan(50.0375)->and($result['bbox'][2])->toBeGreaterThan(50.0375);
    Http::assertSent(fn ($request) => str_contains($request->url(), 'overpass') && str_contains($request['data'], 'supermarket'));
});

it('liczy najbliższe sklepy (po jednym z sieci), przedszkole i szkoły z trasą z OSRM', function () {
    $listing = Listing::factory()->create(['lat' => 50.0400, 'lng' => 22.0000, 'geo_precision' => 'portal']);
    $poi = fn (string $category, string $name, float $lat, ?string $brand = null) => Poi::create(['osm_id' => uniqid(), 'category' => $category, 'name' => $name, 'brand' => $brand, 'lat' => $lat, 'lng' => 22.0]);
    $poi('shop', 'Biedronka', 50.0410, 'Biedronka');
    $poi('shop', 'Biedronka', 50.0450, 'Biedronka');
    $poi('shop', 'Lidl', 50.0500, 'Lidl');
    $poi('kindergarten', 'Przedszkole nr 5', 50.0420);
    $poi('primary', 'Szkoła Podstawowa nr 1', 50.0430);
    $poi('secondary', 'Liceum', 50.2000); // poza promieniem 8 km

    Sleep::fake();
    Http::fake([
        'osrm-bike:5000/*' => Http::response(['code' => 'Ok', 'durations' => [[60, 300, 120, 180, 900]], 'distances' => [[150, 1300, 300, 400, 4200]]]),
        'osrm-car:5000/*' => Http::response(['code' => 'Ok', 'durations' => [[60, 120, 60, 60, 540]], 'distances' => [[300, 1500, 400, 500, 4500]]]),
        'nominatim.openstreetmap.org/*' => Http::response([['lat' => '50.0300', 'lon' => '22.0300', 'display_name' => 'Rycerska 4, Rzeszów']]),
    ]);

    expect(app(Infrastructure::class)->refresh($listing))->toBeTrue();

    $items = $listing->fresh()->infrastructure['items'];
    expect(array_column($items['shop'], 'name'))->toBe(['Biedronka', 'Lidl'])
        ->and($items['shop'][0])->km->toBe(0.15)->bike_min->toBe(1)->walk_min->toBe(2)
        ->and($items['kindergarten'][0]['name'])->toBe('Przedszkole nr 5')
        ->and($items['secondary'])->toBe([])
        ->and($items['nursery'])->toBe([])
        ->and($items['hospital'][0])->name->toBe('Szpital Miejski im. Jana Pawła II')->car_min->toBe(9)->km->toBe(4.2)
        ->and(Infrastructure::lines($listing->fresh()))->toContain('Sklepy: Biedronka 150 m (pieszo 2 min), Lidl 1,3 km (pieszo 16 min)')
        ->and(Infrastructure::lines($listing->fresh()))->toContain('Szpital: Szpital Miejski im. Jana Pawła II 4,2 km (autem 9 min)');
});

it('strona oferty pokazuje infrastrukturę i szczegóły z etykietami', function () {
    $this->actingAs(User::factory()->create());
    Filament::setCurrentPanel('admin');
    $listing = Listing::factory()->create([
        'description' => 'Blok z 2006 roku. Garaż podziemny w cenie. Ogrzewanie miejskie.',
        'infrastructure' => ['items' => ['shop' => [['name' => 'Lidl', 'address' => 'Krakowska 5', 'km' => 0.4, 'by_road' => true, 'walk_min' => 5, 'bike_min' => 2]], 'nursery' => [], 'kindergarten' => [], 'primary' => [], 'secondary' => []]],
    ]);

    Livewire::test(ViewListing::class, ['record' => $listing->getRouteKey()])
        ->assertSee('Infrastruktura')
        ->assertSee('Lidl')
        ->assertSee('400 m')
        ->assertSee('Garaż podziemny w cenie')
        ->assertSee('Budynek z 2006')
        ->assertSee('Mieszkanie i budynek');
});

it('importuje punkty z lokalnej mapy (osmium geojsonseq), ze środkiem budynku i tylko w obszarze', function () {
    Sleep::fake();
    Search::factory()->create(['city' => 'Rzeszów', 'radius_km' => 10]);
    Http::fake(['nominatim.openstreetmap.org/*' => Http::response([['lat' => '50.0375', 'lon' => '22.0049', 'display_name' => 'Rzeszów, województwo podkarpackie, Polska']])]);
    $lines = implode("\n", array_map(fn (array $feature) => "\x1e".json_encode($feature), [
        ['type' => 'Feature', 'geometry' => ['type' => 'Point', 'coordinates' => [22.01, 50.04]], 'properties' => ['@type' => 'node', '@id' => 1, 'shop' => 'supermarket', 'brand' => 'Kaufland']],
        ['type' => 'Feature', 'geometry' => ['type' => 'Polygon', 'coordinates' => [[[22.0, 50.0], [22.02, 50.0], [22.02, 50.02], [22.0, 50.02], [22.0, 50.0]]]], 'properties' => ['@type' => 'way', '@id' => 2, 'amenity' => 'school', 'name' => 'Szkoła Podstawowa nr 5']],
        ['type' => 'Feature', 'geometry' => ['type' => 'Point', 'coordinates' => [19.94, 50.06]], 'properties' => ['@type' => 'node', '@id' => 3, 'shop' => 'supermarket', 'brand' => 'Lidl']],
    ]));
    $stream = fopen('php://memory', 'r+');
    fwrite($stream, $lines);
    rewind($stream);

    $result = app(PoiImporter::class)->importGeoJsonSeq($stream);

    expect($result['total'])->toBe(2)
        ->and(Poi::where('category', 'primary')->sole())->lat->toEqualWithDelta(50.008, 0.001)->osm_id->toBe('way/2')
        ->and(Poi::where('brand', 'Lidl')->exists())->toBeFalse();
});
