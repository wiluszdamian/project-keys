<?php

use App\Ai\Agents\MarketReportAgent;
use App\Enums\DealType;
use App\Models\Listing;
use App\Models\ListingAnalysis;
use App\Models\ListingPriceChange;
use App\Models\MarketReport;
use App\Models\Product;
use App\Models\ProductOffer;
use App\Models\Search;
use App\Models\User;
use App\Services\MarketReportBuilder;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Sleep;

const RENT_HOOK = 'https://discord.com/api/webhooks/5/rent';
const SALE_HOOK = 'https://discord.com/api/webhooks/6/sale';

beforeEach(function () {
    Sleep::fake();
    config([
        'notify.discord.webhook_url' => null,
        'notify.discord.webhook_url_rent' => RENT_HOOK,
        'notify.discord.webhook_url_sale' => SALE_HOOK,
    ]);
    $this->search = Search::factory()->create(['name' => 'Rzeszów 3 pokoje', 'deal_type' => 'rent']);
});

function seenListing(Search $search, array $attributes = []): Listing
{
    $listing = Listing::factory()->create($attributes + ['city' => 'Rzeszów', 'district' => 'Śródmieście']);
    $search->listings()->attach($listing, ['matched_at' => now()]);

    return $listing;
}

it('liczy oferty, mediany, dzielnice, obniżki i najlepsze nowe', function () {
    $old = seenListing($this->search, ['price' => 2000, 'area' => 40, 'price_per_m2' => 50, 'rooms' => 2, 'first_seen_at' => now()->subDays(20)]);
    $new = seenListing($this->search, ['price' => 3000, 'area' => 50, 'price_per_m2' => 60, 'rooms' => 3, 'first_seen_at' => now()->subDays(2)]);
    seenListing($this->search, ['price' => 4000, 'area' => 50, 'price_per_m2' => 80, 'rooms' => 5, 'first_seen_at' => now()->subDay()]);
    seenListing($this->search, ['price' => 9000, 'last_seen_at' => now()->subDays(10), 'first_seen_at' => now()->subDays(30)]);
    Listing::factory()->create(['deal_type' => 'sale', 'price' => 500000]);
    ListingAnalysis::factory()->create(['listing_id' => $new->id, 'search_id' => $this->search->id, 'score' => 8]);
    ListingPriceChange::create(['listing_id' => $old->id, 'old_price' => 2500, 'new_price' => 2000, 'changed_at' => now()->subDay()]);

    $data = app(MarketReportBuilder::class)->build(DealType::Rent, now()->subDays(7), now());

    expect($data['totals'])->toMatchArray(['active' => 3, 'new' => 2, 'median_price' => 3000, 'median_price_per_m2' => 60, 'median_price_change' => null])
        ->and($data['searches'][0])->toMatchArray(['name' => 'Rzeszów 3 pokoje', 'active' => 3, 'new' => 2])
        ->and($data['districts'][0])->toMatchArray(['name' => 'Śródmieście', 'count' => 3, 'median_price_per_m2' => 60])
        ->and(collect($data['rooms'])->pluck('rooms')->all())->toBe(['2', '3', '4+'])
        ->and($data['price_drops'])->toMatchArray(['count' => 1, 'median_percent' => 20.0])
        ->and($data['best_new'][0])->toMatchArray(['id' => $new->id, 'score' => 8])
        ->and($data['has_previous'])->toBeFalse();
});

it('cena skacząca w górę i w dół to najwyżej jedna obniżka', function () {
    $flapping = seenListing($this->search, ['price' => 2400]);
    $up = seenListing($this->search, ['price' => 3000]);
    foreach ([[2500, 1900], [1900, 554894], [554894, 2400]] as $i => [$old, $new]) {
        ListingPriceChange::create(['listing_id' => $flapping->id, 'old_price' => $old, 'new_price' => $new, 'changed_at' => now()->subDays(3)->addMinutes($i)]);
    }
    ListingPriceChange::create(['listing_id' => $up->id, 'old_price' => 3000, 'new_price' => 2000, 'changed_at' => now()->subDays(2)]);
    ListingPriceChange::create(['listing_id' => $up->id, 'old_price' => 2000, 'new_price' => 3000, 'changed_at' => now()->subDay()]);

    $drops = app(MarketReportBuilder::class)->build(DealType::Rent, now()->subDays(7), now())['price_drops'];

    expect($drops['count'])->toBe(1)
        ->and($drops['top'][0])->toMatchArray(['id' => $flapping->id, 'old_price' => 2500, 'new_price' => 2400, 'percent' => 4.0]);
});

it('porównuje z poprzednim raportem', function () {
    MarketReport::create([
        'deal_type' => 'rent',
        'period_start' => now()->subDays(14),
        'period_end' => now()->subDays(7),
        'data' => ['totals' => ['median_price' => 2500, 'median_price_per_m2' => 50], 'searches' => [['id' => $this->search->id, 'median_price' => 2500, 'median_price_per_m2' => 50]]],
    ]);
    seenListing($this->search, ['price' => 3000, 'price_per_m2' => 60]);

    $data = app(MarketReportBuilder::class)->build(DealType::Rent, now()->subDays(7), now());

    expect($data['has_previous'])->toBeTrue()
        ->and($data['totals']['median_price_change'])->toBe(20.0)
        ->and($data['searches'][0]['median_price_per_m2_change'])->toBe(20.0);
});

it('reports:weekly-market zapisuje raport, wysyła na kanał wynajmu i do dzwoneczka', function () {
    Http::fake(['discord.com/*' => Http::response('', 204)]);
    $user = User::factory()->create();
    seenListing($this->search, ['price' => 3000]);

    $this->artisan('reports:weekly-market')->assertSuccessful();

    $report = MarketReport::sole();
    expect($report->deal_type)->toBe(DealType::Rent)
        ->and($report->sent_at)->not->toBeNull();

    Http::assertSentCount(1);
    Http::assertSent(fn ($request) => $request->url() === RENT_HOOK
        && str_contains($request['embeds'][0]['author']['name'], 'Raport tygodnia')
        && str_contains($request['embeds'][0]['fields'][0]['name'], 'Rzeszów 3 pokoje'));

    $notification = $user->notifications()->sole();
    expect($notification->data['title'])->toContain('Raport rynku')
        ->and($notification->data['actions'][0]['url'])->toContain('market-report?report='.$report->id);

    $this->actingAs($user)->get('/admin/market-report?report='.$report->id)->assertOk()->assertSee('Rzeszów 3 pokoje');
    $this->get('/admin/market-report?report=live-rent')->assertOk()->assertSee('na żywo');
});

it('--no-send tylko zapisuje raport', function () {
    Http::fake();
    seenListing($this->search);

    $this->artisan('reports:weekly-market', ['--no-send' => true])->assertSuccessful();

    expect(MarketReport::count())->toBe(1)->and(MarketReport::first()->sent_at)->toBeNull();
    Http::assertNothingSent();
});

it('harmonogram: w sobotę o 20:00, można wyłączyć w ustawieniach', function () {
    $event = collect(app(Schedule::class)->events())
        ->first(fn ($event) => str_contains($event->command ?? '', 'reports:weekly-market'));

    expect($event->expression)->toBe('0 20 * * 6');

    config(['notify.weekly_report' => false]);
    expect($event->filtersPass($this->app))->toBeFalse();

    config(['notify.weekly_report' => true]);
    expect($event->filtersPass($this->app))->toBeTrue();
});

it('API zwraca raporty i ustawienia raportu oraz alertów', function () {
    actingAsOwner();
    MarketReport::create(['deal_type' => 'sale', 'period_start' => now()->subDays(7), 'period_end' => now(), 'data' => ['totals' => ['active' => 5]]]);

    $this->getJson('/api/market-reports?deal_type=sale')->assertOk()->assertJsonPath('data.0.data.totals.active', 5);
    $this->getJson('/api/market-reports?deal_type=rent')->assertOk()->assertJsonCount(0, 'data');

    $this->putJson('/api/notification-settings', ['weekly_report' => false, 'alerts_discord' => true])
        ->assertOk()
        ->assertJsonPath('data.weekly_report', false)
        ->assertJsonPath('data.alerts_discord', true);
});

it('łączy różne zapisy tej samej dzielnicy i pomija oferty spoza wyszukiwań', function () {
    foreach (['Os. Projektant', 'Projektant', 'Os Projketant', 'OŚ.Projektant', 'Drabinianka', 'drabinianka', 'Drabinnianka'] as $district) {
        seenListing($this->search, ['district' => $district, 'price_per_m2' => 50]);
    }
    Listing::factory()->create(['deal_type' => 'rent', 'district' => 'Projektant', 'price' => 1, 'rooms' => 1]);

    $data = app(MarketReportBuilder::class)->build(DealType::Rent, now()->subDays(7), now());

    expect(collect($data['districts'])->pluck('count', 'name')->sortKeys()->all())->toBe(['Drabinianka' => 3, 'Projektant' => 4])
        ->and($data['totals']['active'])->toBe(7)
        ->and(MarketReportBuilder::districtKey('Gen. Władysława Andersa'))->toBe(MarketReportBuilder::districtKey('Generała Władysława Andersa'))
        ->and(MarketReportBuilder::districtKey('Miłocin - św. Huberta'))->toBe(MarketReportBuilder::districtKey('Miłocin-św. Huberta'))
        ->and(MarketReportBuilder::districtKey('1000-Lecia'))->toBe(MarketReportBuilder::districtKey('Tysiąclecia'));
});

it('podaż, koszt z czynszem, trend, twoja lista, okazje i monitor cen', function () {
    seenListing($this->search, ['price' => 2000, 'rent_extra' => 500, 'first_seen_at' => now()->subDays(2)]);
    seenListing($this->search, ['price' => 3000, 'rent_extra' => null, 'first_seen_at' => now()->subDays(10)]);
    $favorite = seenListing($this->search, ['price' => 2800, 'rent_extra' => 300, 'status' => 'favorite', 'first_seen_at' => now()->subDays(12), 'viewing_at' => now()->addDays(2)]);
    ListingPriceChange::create(['listing_id' => $favorite->id, 'old_price' => 3000, 'new_price' => 2800, 'changed_at' => now()->subDays(2)]);
    seenListing($this->search, ['price' => 2500, 'first_seen_at' => now()->subDays(20), 'last_seen_at' => now()->subDays(3), 'inactive_at' => now()->subDays(3)]);
    seenListing($this->search, ['price' => 1900, 'deal_at' => now()->subDay(), 'deal_reasons' => [['type' => 'below_market', 'text' => '20% taniej niż mediana']]]);

    $product = Product::factory()->create(['name' => 'Wózek', 'best_price' => 4500, 'target_price' => 4600]);
    $offer = ProductOffer::factory()->for($product)->create(['price' => 4500]);
    $offer->prices()->create(['price' => 5000, 'available' => true, 'checked_at' => now()->subDays(10)]);
    $offer->prices()->create(['price' => 4500, 'available' => true, 'checked_at' => now()->subDay()]);
    $product->forceFill(['best_offer_id' => $offer->id])->save();

    $data = app(MarketReportBuilder::class)->build(DealType::Rent, now()->subDays(7), now());

    expect($data['totals'])->toMatchArray(['median_total' => 2800, 'total_known' => 2])
        ->and($data['supply'])->toMatchArray(['gone' => 1, 'active_change' => null])
        ->and($data['trend'])->toHaveCount(3)
        ->and(end($data['trend'])['active'])->toBe($data['totals']['active'])
        ->and($data['your_list'][0])->toMatchArray(['id' => $favorite->id, 'status' => 'favorite', 'price' => 2800, 'old_price' => 3000, 'gone' => false])
        ->and($data['your_list'][0]['viewing_at'])->not->toBeNull()
        ->and($data['deals'])->toMatchArray(['count' => 1])
        ->and($data['deals']['top'][0]['reasons'])->toBe('20% taniej niż mediana')
        ->and($data['products'][0])->toMatchArray(['name' => 'Wózek', 'best_price' => 4500.0, 'price_week_ago' => 5000.0, 'change_percent' => -10.0, 'target_reached' => true]);
});

it('AI pisze podsumowanie raportu, które idzie na Discorda i do panelu', function () {
    config(['ai.providers.opencode.key' => 'test-key', 'scoring.enabled' => true]);
    MarketReportAgent::fake([['summary' => 'Ceny stoją, ofert przybyło.', 'points' => ['Zadzwoń w sprawie oferty z obniżką.']]]);
    Http::fake(['discord.com/*' => Http::response('', 204)]);
    $user = User::factory()->create();
    seenListing($this->search, ['price' => 3000]);

    $this->artisan('reports:weekly-market', ['--deal' => ['rent']])->assertSuccessful();

    $report = MarketReport::sole();
    expect($report->data['ai_summary'])->toBe(['text' => 'Ceny stoją, ofert przybyło.', 'points' => ['Zadzwoń w sprawie oferty z obniżką.']]);

    Http::assertSent(fn ($request) => str_contains($request['embeds'][0]['description'], 'Ceny stoją, ofert przybyło.') && str_contains($request['embeds'][0]['description'], '• Zadzwoń'));

    $this->actingAs($user)->get('/admin/market-report?report='.$report->id)->assertOk()->assertSee('Podsumowanie')->assertSee('Ceny stoją, ofert przybyło.');
});
