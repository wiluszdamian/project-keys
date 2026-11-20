<?php

use App\Enums\DealType;
use App\Models\Listing;
use App\Models\RcnTransaction;
use App\Models\Search;
use App\Models\User;
use App\Notifications\Discord\DiscordMessages;
use App\Services\ListingScorer;
use App\Services\MarketReportBuilder;
use App\Services\Rcn\Puwg92;
use App\Services\Rcn\RcnClient;
use App\Services\Rcn\TransactionComparator;
use Filament\Facades\Filament;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Http;

const EMPTY_PAGE = '<?xml version="1.0" encoding="UTF-8"?><wfs:FeatureCollection xmlns:wfs="http://www.opengis.net/wfs/2.0" numberMatched="0" numberReturned="0"></wfs:FeatureCollection>';

// Ok. Rejtana w Rzeszowie.
const RZ_LAT = 50.0206;
const RZ_LNG = 22.0157;

function rcnTransaction(array $attributes = []): RcnTransaction
{
    static $n = 0;
    $n++;

    return RcnTransaction::create($attributes + [
        'rcn_id' => "test-{$n}",
        'teryt' => '1863',
        'date' => now()->subMonths(3)->toDateString(),
        'market' => RcnTransaction::SECONDARY,
        'price' => 500000,
        'area' => 50,
        'price_per_m2' => 10000,
        'rooms' => 3,
        'floor' => 2,
        'lat' => RZ_LAT + 0.001,
        'lng' => RZ_LNG,
    ]);
}

function saleListing(array $attributes = []): Listing
{
    return Listing::factory()->create($attributes + [
        'deal_type' => 'sale',
        'price' => 600000,
        'area' => 50,
        'price_per_m2' => 12000,
        'city' => 'Rzeszów',
        'lat' => RZ_LAT,
        'lng' => RZ_LNG,
        'geo_precision' => 'portal',
    ]);
}

it('przelicza PUWG 1992 na WGS84', function () {
    [$lat, $lng] = Puwg92::toWgs84(246572.743279, 718320.863368);
    expect(round($lat, 3))->toBe(50.046)->and(round($lng, 3))->toBe(22.05);

    // Pałac Kultury w Warszawie.
    [$lat, $lng] = Puwg92::toWgs84(486954, 637130);
    expect(round($lat, 2))->toBe(52.23)->and(round($lng, 2))->toBe(21.01);
});

it('odczytuje sprzedaże mieszkań z GML usługi RCN', function () {
    $records = app(RcnClient::class)->parse(file_get_contents(base_path('tests/Fixtures/rcn/lokale.xml')));

    expect($records)->toHaveCount(2)
        ->and($records[0])->toMatchArray(['teryt' => '1863', 'date' => '2026-08-31', 'market' => 'primary', 'price' => 500000, 'area' => 65.24, 'rooms' => 3, 'floor' => 1, 'price_per_m2' => 7664])
        ->and($records[1])->toMatchArray(['market' => 'secondary', 'price' => 719500, 'area' => 64.15])
        ->and($records[0]['lat'])->toBeGreaterThan(49.9)->toBeLessThan(50.2)
        ->and($records[0]['lng'])->toBeGreaterThan(21.8)->toBeLessThan(22.2)
        ->and($records[0])->not->toHaveKeys(['seller', 'buyer', 'notary']);
});

it('rcn:import pobiera strony do pustej i porównuje oferty sprzedaży', function () {
    $page = file_get_contents(base_path('tests/Fixtures/rcn/lokale.xml'));
    Http::fake(['mapy.geoportal.gov.pl/*' => Http::sequence()->push($page)->push(EMPTY_PAGE)->push($page)->push(EMPTY_PAGE)]);
    $listing = saleListing();

    $this->artisan('rcn:import', ['--county' => ['1863']])->expectsOutputToContain('2 transakcji')->assertSuccessful();

    expect(RcnTransaction::count())->toBe(2)
        ->and($listing->fresh()->transactions_compared_at)->not->toBeNull();
    Http::assertSent(fn ($request) => str_contains(urldecode($request->url()), '<fes:Literal>1863</fes:Literal>')
        && str_contains($request->url(), 'TYPENAMES=ms%3Alokale'));

    // Drugi import nie dubluje.
    $this->artisan('rcn:import', ['--county' => ['1863']])->assertSuccessful();
    expect(RcnTransaction::count())->toBe(2);
});

it('porównuje ofertę z transakcjami w okolicy o podobnym metrażu', function () {
    foreach ([9000, 9500, 10000, 10000, 10500, 11000, 9800, 10200] as $price) {
        rcnTransaction(['price_per_m2' => $price]);
    }
    rcnTransaction(['price_per_m2' => 30000, 'area' => 120]);
    rcnTransaction(['price_per_m2' => 2000, 'lat' => RZ_LAT + 0.2]);
    rcnTransaction(['price_per_m2' => 2000, 'date' => now()->subYears(3)->toDateString()]);

    $result = app(TransactionComparator::class)->compare(saleListing());

    expect($result)->toMatchArray([
        'status' => TransactionComparator::ABOVE,
        'median_price_per_m2' => 10000,
        'offer_price_per_m2' => 12000,
        'diff_percent' => 20.0,
        'count' => 8,
        'radius_m' => 800,
    ])->and($result['nearest'])->toHaveCount(5)
        ->and(TransactionComparator::describe($result))->toStartWith('20% drożej niż mediana transakcji 10 000 zł/m²');
});

it('woli ten sam rynek, gdy wie, że oferta jest z rynku pierwotnego', function () {
    foreach (range(1, 8) as $i) {
        rcnTransaction(['price_per_m2' => 8000, 'market' => RcnTransaction::SECONDARY]);
        rcnTransaction(['price_per_m2' => 12000, 'market' => RcnTransaction::PRIMARY]);
    }

    $primary = saleListing(['portal' => 'nieruchomosci_online', 'raw' => ['market' => 'primary', 'enriched_at' => now()->toIso8601String()]]);
    $unknown = saleListing();

    expect(app(TransactionComparator::class)->compare($primary))->toMatchArray(['market' => 'primary', 'median_price_per_m2' => 12000, 'status' => TransactionComparator::MARKET])
        ->and(app(TransactionComparator::class)->compare($unknown))->toMatchArray(['market' => null, 'count' => 16]);
});

it('przy położeniu z dokładnością do miasta wynik jest oznaczony jako przybliżony', function () {
    foreach (range(1, 8) as $i) {
        rcnTransaction(['lat' => RZ_LAT + 0.03]);
    }
    rcnTransaction(['date' => now()->addMonths(5)->toDateString()]);

    $result = app(TransactionComparator::class)->compare(saleListing(['geo_precision' => 'city']));

    expect($result)->toMatchArray(['approximate' => true, 'radius_m' => 6000, 'count' => 8])
        ->and(TransactionComparator::describe($result))->toStartWith('Położenie oferty znane tylko z dokładnością do miasta');
});

it('rozpoznaje rynek z danych portali', function () {
    expect(saleListing(['raw' => ['market' => 'secondary']])->market())->toBe('secondary')
        ->and(saleListing(['portal' => 'olx', 'raw' => ['params' => [['key' => 'market', 'value' => ['key' => 'primary']]]]])->market())->toBe('primary')
        ->and(saleListing(['portal' => 'domiporta', 'external_id' => 'nowe-123'])->market())->toBe('primary')
        ->and(saleListing()->market())->toBeNull();
});

it('za mało transakcji to osobny wynik, a wynajem i oferta bez współrzędnych są pomijane', function () {
    rcnTransaction();

    expect(app(TransactionComparator::class)->compare(saleListing()))->toMatchArray(['status' => TransactionComparator::TOO_FEW, 'count' => 1])
        ->and(app(TransactionComparator::class)->compare(saleListing(['deal_type' => 'rent'])))->toBeNull()
        ->and(app(TransactionComparator::class)->compare(saleListing(['lat' => null, 'lng' => null])))->toBeNull();
});

it('porównanie trafia do oceny AI, na Discorda, do panelu i raportu', function () {
    foreach (range(1, 8) as $i) {
        rcnTransaction(['date' => now()->subMonth()->toDateString()]);
    }
    $search = Search::factory()->create(['deal_type' => 'sale']);
    $listing = saleListing();
    $search->listings()->attach($listing, ['matched_at' => now()]);
    $listing->forceFill(['transaction_comparison' => app(TransactionComparator::class)->compare($listing)])->save();

    expect(app(ListingScorer::class)->prompt($search, $listing))->toContain('## Ceny transakcyjne')->toContain('mediana: 10000 zł za m2');

    $embed = app(DiscordMessages::class)->listing($listing, $search)['embeds'][0];
    expect(collect($embed['fields'])->firstWhere('name', '📑 Ceny transakcyjne')['value'])->toContain('20% drożej');

    $report = app(MarketReportBuilder::class)->build(DealType::Sale, now()->subDays(7), now());
    expect($report['transactions'])->toMatchArray(['compared' => 1, 'above' => 1])
        ->and($report['transactions']['last_3_months']['secondary'])->toMatchArray(['count' => 8, 'median_price_per_m2' => 10000]);

    Filament::setCurrentPanel('admin');
    $this->actingAs(User::factory()->create())
        ->get("/admin/listings/{$listing->id}")
        ->assertOk()
        ->assertSee('Ceny transakcyjne w okolicy')
        ->assertSee('Najbliższe transakcje');
    $this->get('/admin/listings?tableFilters[transactions][value]=above')->assertOk();
    $this->getJson("/api/listings/{$listing->id}")->assertOk()->assertJsonPath('data.transaction_comparison.status', 'above');
});

it('harmonogram pobiera RCN w niedziele o 4:30', function () {
    $event = collect(app(Schedule::class)->events())->first(fn ($event) => str_contains($event->command ?? '', 'rcn:import'));

    expect($event->expression)->toBe('30 4 * * 0');
});
