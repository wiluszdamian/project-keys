<?php

use App\Enums\DealType;
use App\Models\Listing;
use App\Models\ScrapeRun;
use App\Models\Search;
use App\Scrapers\Data\ListingData;
use App\Services\ListingUpserter;
use App\Services\TimeOnMarket;
use Illuminate\Support\Facades\Cache;

beforeEach(function () {
    Cache::flush();
    // Portal śledzimy od 60 dni: oferty znalezione później mają pewny początek.
    ScrapeRun::factory()->create(['portal' => 'otodom', 'started_at' => now()->subDays(60)]);
});

it('czyta datę dodania z danych portalu i pomija zaślepki', function (string $portal, array $raw, ?string $expected) {
    expect(TimeOnMarket::publishedAt($portal, $raw)?->format('Y-m-d'))->toBe($expected);
})->with([
    'otodom' => ['otodom', ['createdAtFirst' => '2026-11-03T13:57:59+00:00'], '2026-11-03'],
    'otodom zaślepka' => ['otodom', ['createdAtFirst' => '1999-02-29 00:00:01', 'dateCreated' => '2026-11-05T10:00:00Z'], '2026-11-05'],
    'olx' => ['olx', ['created_time' => '2026-11-23T20:24:37+01:00'], '2026-11-23'],
    'morizon' => ['morizon', ['added_at' => '2026-11-20'], '2026-11-20'],
    'domiporta' => ['domiporta', ['date_posted' => '2026-11-19'], '2026-11-19'],
    'rzeszowiak' => ['rzeszowiak', ['added' => '2026-11-24 09:00'], '2026-11-24'],
    'bez daty' => ['adresowo', ['id' => 1], null],
]);

it('liczy dni od daty z portalu albo od pierwszego zobaczenia', function () {
    $published = Listing::factory()->create(['published_at' => now()->subDays(40), 'first_seen_at' => now()->subDays(5)]);
    $seen = Listing::factory()->create(['first_seen_at' => now()->subDays(10)]);

    expect(TimeOnMarket::measure($published))->toMatchArray(['days' => 40, 'known' => true, 'gone' => false])
        ->and(TimeOnMarket::measure($seen))->toMatchArray(['days' => 10, 'known' => true]);
});

it('przy ofercie z pierwszego przebiegu portalu liczba dni to minimum', function () {
    $listing = Listing::factory()->create(['first_seen_at' => now()->subDays(60)->addHour()]);

    expect(TimeOnMarket::measure($listing)['known'])->toBeFalse()
        ->and(TimeOnMarket::describe($listing))->toContain('Na rynku co najmniej 59 dni');
});

it('bierze najwcześniejszy początek z kopii na innych portalach i kończy na zdjęciu ostatniej', function () {
    $main = Listing::factory()->create(['first_seen_at' => now()->subDays(20), 'inactive_at' => now()->subDays(3)]);
    Listing::factory()->create(['portal' => 'olx', 'duplicate_of_id' => $main->id, 'published_at' => now()->subDays(30), 'first_seen_at' => now()->subDays(20), 'inactive_at' => now()->subDays(2)]);

    expect(TimeOnMarket::measure($main->fresh()))->toMatchArray(['days' => 28, 'known' => true, 'gone' => true]);
});

it('oznacza ofertę wiszącą dłużej niż typowe zdjęte podobne', function () {
    foreach ([5, 6, 7, 8, 10, 12] as $days) {
        Listing::factory()->create(['rooms' => 2, 'first_seen_at' => now()->subDays($days + 10), 'inactive_at' => now()->subDays(10)]);
    }

    $fresh = Listing::factory()->create(['rooms' => 2, 'first_seen_at' => now()->subDays(4)]);
    $old = Listing::factory()->create(['rooms' => 2, 'first_seen_at' => now()->subDays(25)]);
    $typical = TimeOnMarket::typical(DealType::Rent, 2);

    expect($typical)->toMatchArray(['count' => 6, 'scope' => '2 pok.', 'median' => 8])
        ->and(TimeOnMarket::assess($fresh)['long'])->toBeFalse()
        ->and(TimeOnMarket::assess($old)['long'])->toBeTrue()
        ->and(TimeOnMarket::longBadge($old))->toBe('Wisi 25 dni')
        ->and(TimeOnMarket::describe($old))->toContain('schodzą zwykle po 8 dni')->toContain('negocjację');
});

it('bez danych o zdjętych ofertach używa domyślnego progu', function () {
    config(['scrapers.time_on_market.long_days.rent' => 30]);
    $listing = Listing::factory()->create(['first_seen_at' => now()->subDays(31)]);

    expect(TimeOnMarket::assess($listing))->toMatchArray(['typical' => null, 'threshold' => 30, 'long' => true]);
});

it('zapisuje datę z portalu przy nowej ofercie i nie przesuwa jej przy odświeżeniu', function () {
    $data = fn (string $created) => new ListingData(
        portal: 'olx', externalId: 'X1', url: 'https://www.olx.pl/d/oferta/x', title: 'Mieszkanie', dealType: DealType::Rent,
        price: 2500, city: 'Rzeszów', raw: ['created_time' => $created],
    );

    $listing = app(ListingUpserter::class)->upsert($data('2026-11-01T10:00:00+01:00'))->listing;
    app(ListingUpserter::class)->upsert($data('2026-11-20T10:00:00+01:00'));

    expect($listing->fresh()->published_at->format('Y-m-d'))->toBe('2026-11-01');
});

it('daje dane do raportu tygodniowego', function () {
    $search = Search::factory()->create();
    Listing::factory()->create(['rooms' => 2, 'first_seen_at' => now()->subDays(12), 'inactive_at' => now()->subDays(2)]);
    $long = Listing::factory()->create(['rooms' => 3, 'first_seen_at' => now()->subDays(45), 'title' => 'Długo wisi']);
    $long->searches()->attach($search, ['matched_at' => now()]);

    $week = TimeOnMarket::weekly(DealType::Rent, now()->subWeek(), now());

    expect($week)->toMatchArray(['gone' => 1, 'known' => 1, 'median_days' => 10])
        ->and($week['by_rooms'])->toBe([['rooms' => '2', 'count' => 1, 'median_days' => 10]])
        ->and($week['longest'][0])->toMatchArray(['title' => 'Długo wisi', 'days' => 45]);
});
