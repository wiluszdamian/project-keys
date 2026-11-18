<?php

use App\Enums\ScrapeRunStatus;
use App\Events\ListingMatched;
use App\Jobs\RunSearchJob;
use App\Jobs\ScrapePortalJob;
use App\Models\ScrapeRun;
use App\Models\Search;
use Illuminate\Bus\PendingBatch;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Sleep;

it('tworzy batch z jobem dla każdego portalu', function () {
    Bus::fake();
    $search = Search::factory()->create(['portals' => ['otodom', 'olx']]);

    app()->call([new RunSearchJob($search), 'handle']);

    expect($search->fresh()->last_run_at)->not->toBeNull();
    Bus::assertBatched(fn (PendingBatch $batch) => $batch->jobs->count() === 2
        && $batch->jobs->every(fn ($job) => $job instanceof ScrapePortalJob && $job->duringFirstRun)
        && $batch->jobs->pluck('portal')->all() === ['otodom', 'olx']
        && $batch->queue() === 'scrape');
});

it('pomija portale bez scrapera', function () {
    Bus::fake();
    $search = Search::factory()->create(['portals' => ['nieistniejacy']]);

    app()->call([new RunSearchJob($search), 'handle']);

    Bus::assertNothingBatched();
});

it('pełny przebieg na obu portalach kończy pierwszy przebieg', function () {
    Sleep::fake();
    Event::fake([ListingMatched::class]);
    config(['scrapers.max_pages' => 1]);
    Http::fake([
        'www.otodom.pl/*' => Http::response(file_get_contents(base_path('tests/Fixtures/otodom/search_rent.html'))),
        'www.olx.pl/api/v1/friendly-links/*' => Http::response(['data' => ['category_id' => 15, 'region_id' => 4, 'city_id' => 8959]]),
        'www.olx.pl/api/v1/offers/*' => Http::response(file_get_contents(base_path('tests/Fixtures/olx/search_krakow.json'))),
        'discord.com/*' => Http::response('', 204),
    ]);
    config(['notify.discord.webhook_url' => 'https://discord.com/api/webhooks/1/abc']);
    $search = Search::factory()->create(['notify_channels' => ['discord'],
        'city' => 'Kraków', 'price_min' => null, 'price_max' => null, 'area_min' => null, 'area_max' => null,
        'rooms_min' => null, 'rooms_max' => null, 'portals' => ['otodom', 'olx'],
    ]);

    RunSearchJob::dispatchSync($search);

    expect(ScrapeRun::pluck('status', 'portal')->all())->toBe(['otodom' => ScrapeRunStatus::Ok, 'olx' => ScrapeRunStatus::Ok])
        ->and($search->fresh()->first_run_completed)->toBeTrue()
        ->and($search->listings()->count())->toBeGreaterThan(70);

    Event::assertDispatched(ListingMatched::class, fn ($event) => $event->duringFirstRun);

    // Pierwszy przebieg: jedno podsumowanie na Discordzie, bez duplikatów z drugiego portalu.
    Http::assertSent(fn ($request) => str_contains($request->url(), 'discord.com') && str_starts_with($request['embeds'][0]['title'], 'Pierwszy przebieg'));
    expect(Http::recorded(fn ($request) => str_contains($request->url(), 'discord.com')))->toHaveCount(1);
});
