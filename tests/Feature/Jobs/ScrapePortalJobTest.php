<?php

use App\Enums\ScrapeRunStatus;
use App\Events\ListingMatched;
use App\Events\ListingPriceChanged;
use App\Events\PortalBlockedRepeatedly;
use App\Jobs\ScrapePortalJob;
use App\Models\Listing;
use App\Models\ScrapeRun;
use App\Models\Search;
use App\Scrapers\HttpFetcher;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Sleep;

beforeEach(function () {
    Sleep::fake();
    Event::fake([ListingMatched::class, ListingPriceChanged::class, PortalBlockedRepeatedly::class]);
    config(['scrapers.max_pages' => 1]);

    // Wyszukiwanie dopasowane do fixture Otodom (Kraków, wynajem, 2-3 pokoje do 4000 zł).
    $this->search = Search::factory()->create([
        'city' => 'Kraków', 'districts' => null, 'price_min' => null, 'price_max' => 4000,
        'area_min' => null, 'area_max' => null, 'rooms_min' => 2, 'rooms_max' => 3, 'portals' => ['otodom'],
    ]);
    $this->fixture = file_get_contents(base_path('tests/Fixtures/otodom/search_rent.html'));
});

it('zapisuje oferty, dopasowania i przebieg', function () {
    Http::fake(['www.otodom.pl/*' => Http::response($this->fixture)]);

    ScrapePortalJob::dispatchSync($this->search, 'otodom', true);

    $run = ScrapeRun::sole();
    expect($run)
        ->status->toBe(ScrapeRunStatus::Ok)
        ->pages_fetched->toBe(1)
        ->found_count->toBe(36)
        ->new_count->toBe(36)
        ->finished_at->not->toBeNull()
        ->and(Listing::count())->toBe(36)
        ->and($this->search->listings()->count())->toBe(36);

    Event::assertDispatchedTimes(ListingMatched::class, 36);
    Event::assertDispatched(ListingMatched::class, fn ($event) => $event->duringFirstRun && $event->search->is($this->search));
});

it('drugi przebieg nie daje nowych dopasowań, ale łapie zmianę ceny', function () {
    Http::fake(['www.otodom.pl/*' => Http::sequence()
        ->push($this->fixture)
        ->push(str_replace('"totalPrice": {"value": 3000,', '"totalPrice": {"value": 2700,', $this->fixture))]);

    ScrapePortalJob::dispatchSync($this->search, 'otodom');
    ScrapePortalJob::dispatchSync($this->search, 'otodom');

    expect(ScrapeRun::latest('id')->first()->new_count)->toBe(0)
        ->and(Listing::where('external_id', 'ID4E1RZ')->value('price'))->toBe(2700);

    Event::assertDispatchedTimes(ListingMatched::class, 36);
    Event::assertDispatched(ListingPriceChanged::class, fn ($event) => $event->oldPrice === 3000 && $event->newPrice === 2700);
});

it('pomija oferty niezgodne z filtrami', function () {
    Http::fake(['www.otodom.pl/*' => Http::response($this->fixture)]);
    $this->search->update(['districts' => ['Czyżyny']]);

    ScrapePortalJob::dispatchSync($this->search, 'otodom');

    expect(ScrapeRun::sole()->found_count)->toBe(36)
        ->and(ScrapeRun::sole()->new_count)->toBe($this->search->listings()->count())
        ->and($this->search->listings()->pluck('district')->unique()->all())->toBe(['Czyżyny']);
});

it('zapisuje blokadę i wstrzymuje portal', function () {
    Http::fake(['www.otodom.pl/*' => Http::response('', 429)]);

    ScrapePortalJob::dispatchSync($this->search, 'otodom');

    expect(ScrapeRun::sole())->status->toBe(ScrapeRunStatus::Blocked)->error_message->toContain('429')
        ->and(app(HttpFetcher::class)->isPaused('otodom'))->toBeTrue();

    Event::assertNotDispatched(PortalBlockedRepeatedly::class);
});

it('po 3 blokadach z rzędu wysyła zdarzenie do alertu', function () {
    ScrapeRun::factory()->count(2)->for($this->search)->create(['portal' => 'otodom', 'status' => 'blocked', 'started_at' => now()->subHours(3)]);
    Http::fake(['www.otodom.pl/*' => Http::response('', 403)]);

    ScrapePortalJob::dispatchSync($this->search, 'otodom');

    Event::assertDispatched(PortalBlockedRepeatedly::class, fn ($event) => $event->portal === 'otodom' && $event->consecutiveBlocks === 3);
});

it('udany przebieg między blokadami zeruje licznik', function () {
    ScrapeRun::factory()->for($this->search)->create(['portal' => 'otodom', 'status' => 'blocked', 'started_at' => now()->subHours(5)]);
    ScrapeRun::factory()->for($this->search)->create(['portal' => 'otodom', 'status' => 'ok', 'started_at' => now()->subHours(4)]);
    ScrapeRun::factory()->for($this->search)->create(['portal' => 'otodom', 'status' => 'blocked', 'started_at' => now()->subHours(3)]);
    Http::fake(['www.otodom.pl/*' => Http::response('', 403)]);

    ScrapePortalJob::dispatchSync($this->search, 'otodom');

    Event::assertNotDispatched(PortalBlockedRepeatedly::class);
});

it('nic nie robi, gdy portal jest wstrzymany', function () {
    Http::fake();
    app(HttpFetcher::class)->pause('otodom');

    ScrapePortalJob::dispatchSync($this->search, 'otodom');

    expect(ScrapeRun::count())->toBe(0);
    Http::assertNothingSent();
});

it('zapisuje błąd scrapera', function () {
    Http::fake(['www.otodom.pl/*' => Http::response('<html>nowa strona</html>')]);

    ScrapePortalJob::dispatchSync($this->search, 'otodom');

    expect(ScrapeRun::sole())->status->toBe(ScrapeRunStatus::Error)->error_message->toContain('__NEXT_DATA__');
});
