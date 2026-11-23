<?php

use App\Enums\ListingStatus;
use App\Filament\Resources\Listings\Pages\ListListings;
use App\Models\Listing;
use App\Models\ListingAnalysis;
use App\Models\ListingPriceChange;
use App\Models\Search;
use App\Models\User;
use App\Services\DealFinder;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;

const DEALS_HOOK = 'https://discord.com/api/webhooks/9/deals';

beforeEach(function () {
    config(['notify.discord.webhook_url' => DEALS_HOOK, 'notify.deals' => true]);
    $this->search = Search::factory()->create(['deal_type' => 'sale', 'city' => 'Rzeszów', 'notify_channels' => ['discord']]);
});

// Pełny wynik porównania z RCN, jak z TransactionComparator.
function rcnBelow(float $diff, bool $approximate = false): array
{
    return ['status' => 'below', 'diff_percent' => $diff, 'median_price_per_m2' => 10_000, 'p25_price_per_m2' => 9000, 'p75_price_per_m2' => 11_000,
        'offer_price_per_m2' => 9000, 'count' => 12, 'radius_m' => 800, 'months' => 24, 'market' => null, 'approximate' => $approximate];
}

function dealListing(array $attributes = []): Listing
{
    $listing = Listing::factory()->create(array_merge([
        'deal_type' => 'sale', 'city' => 'Rzeszów', 'district' => null, 'price' => 450_000, 'area' => 50, 'price_per_m2' => 9000,
    ], $attributes));
    test()->search->listings()->attach($listing, ['matched_at' => now()]);

    return $listing;
}

it('okazja: wyraźnie taniej niż transakcje, ale nie przy porównaniu z całym miastem', function () {
    $finder = app(DealFinder::class);
    $below = dealListing(['transaction_comparison' => rcnBelow(-14.2)]);
    $slightly = dealListing(['transaction_comparison' => rcnBelow(-8)]);
    $approximate = dealListing(['transaction_comparison' => rcnBelow(-20, true)]);

    expect($finder->reasons($below))->toBe([['type' => DealFinder::BELOW_TRANSACTIONS, 'text' => '14% taniej niż transakcje w okolicy']])
        ->and($finder->reasons($slightly))->toBe([])
        ->and($finder->reasons($approximate))->toBe([]);
});

it('okazja: cena spadła co najmniej o 5% od najwyższej', function () {
    $listing = dealListing(['price' => 470_000]);
    ListingPriceChange::create(['listing_id' => $listing->id, 'old_price' => 500_000, 'new_price' => 490_000, 'changed_at' => now()->subDays(3)]);
    ListingPriceChange::create(['listing_id' => $listing->id, 'old_price' => 490_000, 'new_price' => 470_000, 'changed_at' => now()]);

    expect(DealFinder::summary(app(DealFinder::class)->reasons($listing)))->toBe('cena spadła o 6% (z 500 000 zł)');
});

it('okazja: taniej za m² niż mediana ofert, ale nie podejrzanie tanio', function () {
    Listing::factory()->count(12)->create(['deal_type' => 'sale', 'city' => 'Rzeszów', 'district' => null, 'price' => 500_000, 'area' => 50, 'price_per_m2' => 10_000, 'last_seen_at' => now()]);
    $cheap = dealListing(['price' => 400_000, 'price_per_m2' => 8000]);
    $suspicious = dealListing(['price' => 250_000, 'price_per_m2' => 5000]);

    expect(app(DealFinder::class)->reasons($cheap)[0]['text'])->toBe('20% taniej za m² niż podobne oferty (miasto Rzeszów, podobny metraż)')
        ->and(app(DealFinder::class)->reasons($suspicious))->toBe([]);
});

it('słabo oceniona oferta nie jest okazją, a przy wynajmie próg jest wyższy', function () {
    $weak = dealListing(['transaction_comparison' => rcnBelow(-20)]);
    ListingAnalysis::factory()->create(['listing_id' => $weak->id, 'search_id' => $this->search->id, 'score' => 4]);
    Listing::factory()->count(12)->create(['deal_type' => 'rent', 'city' => 'Rzeszów', 'district' => null, 'price' => 3000, 'area' => 60, 'rent_extra' => null, 'last_seen_at' => now()]);
    $rent20 = Listing::factory()->create(['deal_type' => 'rent', 'city' => 'Rzeszów', 'district' => null, 'price' => 2400, 'area' => 60, 'rent_extra' => null]);
    $rent30 = Listing::factory()->create(['deal_type' => 'rent', 'city' => 'Rzeszów', 'district' => null, 'price' => 2100, 'area' => 60, 'rent_extra' => null]);

    expect(app(DealFinder::class)->reasons($weak))->toBe([])
        ->and(app(DealFinder::class)->reasons($rent20))->toBe([])
        ->and(app(DealFinder::class)->reasons($rent30)[0]['type'])->toBe(DealFinder::BELOW_MARKET);
});

it('deals:refresh wysyła okazję raz, a kilka naraz jako jedno podsumowanie', function () {
    Http::fake([DEALS_HOOK => Http::response('', 204)]);
    $one = dealListing(['transaction_comparison' => rcnBelow(-15)]);

    $this->artisan('deals:refresh')->assertSuccessful();
    $this->artisan('deals:refresh')->assertSuccessful();

    Http::assertSentCount(1);
    Http::assertSent(fn ($request) => str_contains($request['embeds'][0]['author']['name'], 'Okazja')
        && str_contains($request['embeds'][0]['description'], '15% taniej niż transakcje'));
    expect($one->fresh())->deal_at->not->toBeNull()->deal_notified_at->not->toBeNull()
        ->and(User::count() === 0 || DB::table('notifications')->count() > 0)->toBeTrue();

    foreach (range(1, 4) as $i) {
        dealListing(['transaction_comparison' => rcnBelow(-12 - $i)]);
    }

    $this->artisan('deals:refresh')->assertSuccessful();

    Http::assertSentCount(2);
    Http::assertSent(fn ($request) => str_contains($request['embeds'][0]['title'] ?? '', '4 ofert tańszych'));
});

it('bez drugiej wiadomości, gdy okazja przyszła już jako nowa oferta; odrzucone i --silent bez wysyłki', function () {
    Http::fake([DEALS_HOOK => Http::response('', 204)]);
    $sent = dealListing(['transaction_comparison' => rcnBelow(-15), 'deal_at' => now()->subHour(), 'deal_reasons' => [['type' => 'x', 'text' => 'x']]]);
    DB::table('search_listing')->where('listing_id', $sent->id)->update(['notified_at' => now()]);
    dealListing(['transaction_comparison' => rcnBelow(-15), 'status' => ListingStatus::Rejected]);

    $this->artisan('deals:refresh')->assertSuccessful();
    Http::assertNothingSent();
    expect($sent->fresh()->deal_notified_at)->not->toBeNull();

    dealListing(['transaction_comparison' => rcnBelow(-15)]);
    $this->artisan('deals:refresh', ['--silent' => true])->assertSuccessful();
    Http::assertNothingSent();
});

it('oferta, której nie ma już na portalu, przestaje być okazją', function () {
    $old = dealListing(['deal_at' => now()->subDays(5), 'deal_reasons' => [['type' => 'x', 'text' => 'x']], 'last_seen_at' => now()->subDays(5)]);

    $this->artisan('deals:refresh', ['--silent' => true])->assertSuccessful();

    expect($old->fresh())->deal_at->toBeNull()->deal_reasons->toBeNull();
});

it('zakładka Okazje na liście ofert', function () {
    $this->actingAs(User::factory()->create());
    Filament::setCurrentPanel('admin');
    $deal = dealListing(['deal_at' => now(), 'deal_reasons' => [['type' => 'x', 'text' => '14% taniej niż transakcje w okolicy']]]);
    $plain = dealListing();

    Livewire::withQueryParams(['tab' => 'deals'])
        ->test(ListListings::class)
        ->removeTableFilter('above_threshold')
        ->assertCanSeeTableRecords([$deal])
        ->assertCanNotSeeTableRecords([$plain]);
});
