<?php

use App\Filament\Pages\MortgagePage;
use App\Filament\Resources\Listings\Pages\ViewListing;
use App\Models\Listing;
use App\Models\User;
use App\Services\Mortgage\MortgageCalculator;
use App\Services\Mortgage\NbpRates;
use App\Services\Mortgage\RkmSimulator;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;

function rkmInput(array $overrides = []): array
{
    return array_merge(RkmSimulator::defaults(), [
        'fixed_rate' => 6.2, 'index_rate' => 3.49, 'margin' => 2.3, 'bridge' => false,
    ], $overrides);
}

it('liczy ratę równą', function () {
    // Wartości kontrolne z kalkulatorów kredytowych.
    expect(round(MortgageCalculator::annuity(500_000, 5.69, 360)))->toBe(2899.0)
        ->and(round(MortgageCalculator::annuity(540_000, 5.69, 360)))->toBe(3131.0)
        ->and(MortgageCalculator::annuity(120_000, 0, 120))->toBe(1000.0);
});

it('harmonogram spłaca cały kredyt i przelicza ratę po zmianie stopy', function () {
    $schedule = MortgageCalculator::schedule(300_000, 360, [1 => 6.0, 61 => 5.0]);

    expect($schedule['months'])->toBe(360)
        ->and(last($schedule['years'])['balance'])->toBeLessThan(0.01)
        ->and($schedule['periods'])->toHaveCount(2)
        ->and($schedule['periods'][1]['installment'])->toBeLessThan($schedule['periods'][0]['installment']);
});

it('przy 550 000 zł gwarancja BGK to 100 000 zł i trzeba 10 000 zł wkładu', function () {
    $result = app(RkmSimulator::class)->simulate(rkmInput(['price' => 550_000, 'own' => 0, 'rate_type' => 'variable']));

    expect($result)
        ->required_own->toBe(10_000.0)
        ->own->toBe(10_000.0)
        ->guarantee->toBe(100_000.0)
        ->loan->toBe(540_000.0)
        ->and(round($result['installment']))->toBe(round(MortgageCalculator::annuity(540_000, 5.79, 360)))
        ->and($result['fees']['guarantee_fee'])->toBe(1000.0)
        ->and($result['warnings'])->toHaveCount(1);
});

it('do 500 000 zł kredyt na pełną kwotę', function () {
    $result = app(RkmSimulator::class)->simulate(rkmInput(['price' => 480_000, 'own' => 0]));

    expect($result)->own->toBe(0.0)->guarantee->toBe(96_000.0)->loan->toBe(480_000.0)->warnings->toBe([]);
});

it('stałe oprocentowanie zmienia się po 5 latach, a pomostowe po 3 miesiącach', function () {
    $result = app(RkmSimulator::class)->simulate(rkmInput(['price' => 500_000, 'rate_type' => 'fixed', 'bridge' => true]));

    expect(array_column($result['schedule']['periods'], 'from'))->toBe([1, 4, 61])
        ->and(array_column($result['schedule']['periods'], 'rate'))->toBe([7.2, 6.2, 5.79])
        ->and($result['installment_bridge'])->toBeGreaterThan($result['installment'])
        ->and($result['stress_installment'])->toBeGreaterThan($result['installment_after_fixed']);
});

it('sprawdza limit ceny za m²', function () {
    $simulator = app(RkmSimulator::class);

    expect($simulator->simulate(rkmInput(['price' => 500_000, 'area' => 50, 'zone' => 'rzeszow']))['limit']['ok'])->toBeTrue()
        ->and($simulator->simulate(rkmInput(['price' => 550_000, 'area' => 50, 'zone' => 'neighbour'])))
        ->limit->ok->toBeFalse()
        ->warnings->toContain('Cena za m² jest wyższa niż limit RKM (10 657 zł za m² dla: gmina sąsiadująca z Rzeszowem). Bank nie udzieli RKM na to mieszkanie.');
});

it('spłata rodzinna obniża odsetki', function () {
    $result = app(RkmSimulator::class)->simulate(rkmInput(['price' => 500_000, 'second_child_year' => 2, 'third_child_year' => 5]));

    expect($result['family_repayments'])->toBe(80_000)
        ->and($result['with_children']['interest'])->toBeLessThan($result['schedule']['interest']);
});

it('rozpoznaje strefę po miejscowości', function () {
    expect(RkmSimulator::zone('Rzeszów'))->toBe('rzeszow')
        ->and(RkmSimulator::zone('Tajęcina'))->toBe('neighbour')
        ->and(RkmSimulator::zone('Boguchwała'))->toBe('neighbour')
        ->and(RkmSimulator::zone('Krosno'))->toBe('other');
});

it('czyta stopę referencyjną NBP', function () {
    Http::fake(['static.nbp.pl/*' => Http::response('<stopy_procentowe><tabela><pozycja id="ref" oprocentowanie="3,75" obowiazuje_od="2026-03-05"/></tabela></stopy_procentowe>')]);

    expect(app(NbpRates::class)->reference())->toBe(['rate' => 3.75, 'since' => '2026-03-05']);
});

it('strona kredytu liczy dla oferty i ostrzega o zmianie stóp NBP', function () {
    Http::fake(['static.nbp.pl/*' => Http::response('<pozycja id="ref" oprocentowanie="3,50" obowiazuje_od="2026-12-03"/>')]);
    $this->actingAs(User::factory()->create());
    Filament::setCurrentPanel('admin');
    $listing = Listing::factory()->create(['deal_type' => 'sale', 'price' => 450_000, 'area' => 60, 'city' => 'Boguchwała', 'title' => 'Mieszkanie w Boguchwale']);

    Livewire::withQueryParams(['listing' => $listing->id])
        ->test(MortgagePage::class)
        ->assertFormSet(['price' => 450_000, 'zone' => 'neighbour', 'own' => 0.0])
        ->assertSee('Mieszkanie w Boguchwale')
        ->assertSee('Mieszkanie się mieści.')
        ->assertSee('NBP zmienił stopę referencyjną')
        ->fillForm(['price' => 550_000, 'own' => 0])
        ->assertSee('trzeba mieć co najmniej 10 000 zł');

    Livewire::test(ViewListing::class, ['record' => $listing->getRouteKey()])
        ->assertSee('Rata kredytu (RKM)')
        ->assertActionVisible('mortgage');
});
