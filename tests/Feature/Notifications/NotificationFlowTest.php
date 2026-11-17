<?php

use App\Events\ListingPriceChanged;
use App\Events\PortalBlockedRepeatedly;
use App\Jobs\AnalyzeListingJob;
use App\Jobs\NotifyRunResultsJob;
use App\Jobs\SendDigestJob;
use App\Jobs\SendListingNotificationJob;
use App\Models\Listing;
use App\Models\ListingAnalysis;
use App\Models\ScrapeRun;
use App\Models\Search;
use App\Notifications\Notifier;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Sleep;

const HOOK = 'https://discord.com/api/webhooks/1/abc';

beforeEach(function () {
    Sleep::fake();
    config(['notify.discord.webhook_url' => HOOK, 'notify.digest_threshold' => 5]);
    $this->search = Search::factory()->create(['notify_channels' => ['discord']]);
});

function matchListings(Search $search, int $count, array $pivot = []): array
{
    $listings = Listing::factory()->count($count)->create();
    foreach ($listings as $listing) {
        $search->listings()->attach($listing, array_merge(['matched_at' => now()], $pivot));
    }

    return $listings->modelKeys();
}

function pivot(Search $search, int $listingId): object
{
    return DB::table('search_listing')->where('search_id', $search->id)->where('listing_id', $listingId)->first();
}

it('pierwszy przebieg daje jedno podsumowanie', function () {
    Queue::fake();
    $ids = matchListings($this->search, 3);

    (new NotifyRunResultsJob($this->search, now()->subMinute()->toIso8601String(), true))->handle(app(Notifier::class));

    Queue::assertPushed(SendDigestJob::class, fn ($job) => $job->listingIds == $ids && $job->firstRun);
    Queue::assertNotPushed(SendListingNotificationJob::class);
});

it('kilka nowych ofert idzie osobno, dużo jako podsumowanie', function (int $count, bool $digest) {
    Queue::fake();
    matchListings($this->search, $count);

    (new NotifyRunResultsJob($this->search, now()->subMinute()->toIso8601String(), false))->handle(app(Notifier::class));

    if ($digest) {
        Queue::assertPushed(SendDigestJob::class);
        Queue::assertNotPushed(SendListingNotificationJob::class);
    } else {
        Queue::assertPushed(SendListingNotificationJob::class, $count);
        Queue::assertNotPushed(SendDigestJob::class);
    }
})->with([
    '5 ofert' => [5, false],
    '6 ofert' => [6, true],
]);

it('pomija duplikaty, już wysłane i starsze dopasowania', function () {
    Queue::fake();
    matchListings($this->search, 1, ['notify_skipped_reason' => 'duplicate']);
    matchListings($this->search, 1, ['notified_at' => now()]);
    matchListings($this->search, 1, ['matched_at' => now()->subHour()]);
    $fresh = matchListings($this->search, 1);

    (new NotifyRunResultsJob($this->search, now()->subMinute()->toIso8601String(), false))->handle(app(Notifier::class));

    Queue::assertPushed(SendListingNotificationJob::class, 1);
    Queue::assertPushed(SendListingNotificationJob::class, fn ($job) => $job->listing->id === $fresh[0]);
});

it('bez skonfigurowanego kanału oznacza oferty jako pominięte', function () {
    Queue::fake();
    config(['notify.discord.webhook_url' => null]);
    [$id] = matchListings($this->search, 1);

    (new NotifyRunResultsJob($this->search, now()->subMinute()->toIso8601String(), false))->handle(app(Notifier::class));

    Queue::assertNothingPushed();
    expect(pivot($this->search, $id)->notify_skipped_reason)->toBe('no_channel');
});

it('wysyła ofertę raz i zapisuje notified_at', function () {
    Http::fake([HOOK => Http::response('', 204)]);
    [$id] = matchListings($this->search, 1);
    $listing = Listing::find($id);

    SendListingNotificationJob::dispatchSync($this->search, $listing);
    SendListingNotificationJob::dispatchSync($this->search, $listing);

    Http::assertSentCount(1);
    expect(pivot($this->search, $id)->notified_at)->not->toBeNull();
});

it('nieudana wysyłka nie ustawia notified_at', function () {
    Http::fake([HOOK => Http::response('', 400)]);
    [$id] = matchListings($this->search, 1);

    expect(fn () => (new SendListingNotificationJob($this->search, Listing::find($id)))->handle(app(Notifier::class)))
        ->toThrow(RuntimeException::class);

    expect(pivot($this->search, $id)->notified_at)->toBeNull();
});

it('podsumowanie pokazuje 10 ofert, resztę oznacza jako digest', function () {
    Http::fake([HOOK => Http::response('', 204)]);
    $ids = matchListings($this->search, 12);

    SendDigestJob::dispatchSync($this->search, $ids, true);

    Http::assertSentCount(1);
    Http::assertSent(fn ($request) => str_contains($request['embeds'][0]['title'], '12 ofert'));
    expect(DB::table('search_listing')->whereNotNull('notified_at')->count())->toBe(10)
        ->and(DB::table('search_listing')->where('notify_skipped_reason', 'digest')->count())->toBe(2);
});

it('podsumowanie pokazuje tylko oferty od progu', function () {
    Http::fake([HOOK => Http::response('', 204)]);
    $this->search->update(['min_score_to_notify' => 6]);
    [$good, $weak, $noScore] = matchListings($this->search, 3);
    ListingAnalysis::factory()->create(['search_id' => $this->search->id, 'listing_id' => $good, 'score' => 8]);
    ListingAnalysis::factory()->create(['search_id' => $this->search->id, 'listing_id' => $weak, 'score' => 3]);

    SendDigestJob::dispatchSync($this->search, [$good, $weak, $noScore], true);

    Http::assertSent(fn ($request) => str_contains($request['embeds'][0]['title'], '3 oferty pasuje do filtrów, 2 z oceną od 6')
        && ! str_contains($request['embeds'][0]['description'], Listing::find($weak)->url));
    expect(pivot($this->search, $weak)->notify_skipped_reason)->toBe('digest')
        ->and(pivot($this->search, $good)->notified_at)->not->toBeNull();
});

it('podsumowanie bez dobrych ofert mówi to wprost', function () {
    Http::fake([HOOK => Http::response('', 204)]);
    [$weak] = matchListings($this->search, 1);
    ListingAnalysis::factory()->create(['search_id' => $this->search->id, 'listing_id' => $weak, 'score' => 2]);

    SendDigestJob::dispatchSync($this->search, [$weak], true);

    Http::assertSent(fn ($request) => str_contains($request['embeds'][0]['description'], 'Żadna oferta nie dostała oceny od 6'));
});

it('wysyła obniżkę tylko dla już wysłanej oferty', function (bool $notified, int $old, int $new, int $sent) {
    Http::fake([HOOK => Http::response('', 204)]);
    [$id] = matchListings($this->search, 1, $notified ? ['notified_at' => now()] : []);

    ListingPriceChanged::dispatch($this->search, Listing::find($id), $old, $new);

    Http::assertSentCount($sent);
})->with([
    'obniżka wysłanej' => [true, 3000, 2700, 1],
    'podwyżka' => [true, 3000, 3200, 0],
    'obniżka niewysłanej' => [false, 3000, 2700, 0],
]);

it('alert o blokadzie najwyżej raz na 6 godzin', function () {
    Http::fake([HOOK => Http::response('', 204)]);

    PortalBlockedRepeatedly::dispatch('olx', 3, 'Portal olx odpowiedział 403');
    PortalBlockedRepeatedly::dispatch('olx', 4, 'Portal olx odpowiedział 403');

    Http::assertSentCount(1);
    Http::assertSent(fn ($request) => str_contains($request['embeds'][0]['title'], 'olx'));

    $this->travel(7)->hours();
    PortalBlockedRepeatedly::dispatch('olx', 5, 'Portal olx odpowiedział 403');
    Http::assertSentCount(2);
});

it('alerts:check zgłasza brak udanego przebiegu od 3 godzin', function () {
    Http::fake([HOOK => Http::response('', 204)]);
    ScrapeRun::factory()->for($this->search)->create(['status' => 'ok', 'finished_at' => now()->subHours(4)]);

    $this->artisan('alerts:check')->assertSuccessful();
    Http::assertSentCount(1);

    ScrapeRun::factory()->for($this->search)->create(['status' => 'ok', 'finished_at' => now()->subMinutes(10)]);
    Cache::flush();
    $this->artisan('alerts:check')->assertSuccessful();
    Http::assertSentCount(1);
});

it('przy włączonym AI najpierw ocenia oferty w batchu', function () {
    Bus::fake();
    config(['ai.providers.opencode.key' => 'test-key']);
    $ids = matchListings($this->search, 2);

    (new NotifyRunResultsJob($this->search, now()->subMinute()->toIso8601String(), false))->handle(app(Notifier::class));

    Bus::assertBatched(fn ($batch) => $batch->jobs->count() === 2
        && $batch->jobs->every(fn ($job) => $job instanceof AnalyzeListingJob)
        && $batch->queue() === 'ai');
});

it('po ocenie wysyła tylko oferty od progu i te bez oceny', function () {
    Queue::fake();
    config(['ai.providers.opencode.key' => 'test-key']);
    $this->search->update(['min_score_to_notify' => 6]);
    [$good, $weak, $noScore] = matchListings($this->search, 3);
    ListingAnalysis::factory()->create(['search_id' => $this->search->id, 'listing_id' => $good, 'score' => 7]);
    ListingAnalysis::factory()->create(['search_id' => $this->search->id, 'listing_id' => $weak, 'score' => 4]);

    (new NotifyRunResultsJob($this->search, now()->subMinute()->toIso8601String(), false, analyzed: true))->handle(app(Notifier::class));

    Queue::assertPushed(SendListingNotificationJob::class, 2);
    expect(pivot($this->search, $weak)->notify_skipped_reason)->toBe('below_threshold');
});

it('alerts:check zgłasza dużo błędów AI', function () {
    Http::fake([HOOK => Http::response('', 204)]);
    ScrapeRun::factory()->for($this->search)->create(['status' => 'ok', 'finished_at' => now()]);
    Cache::put('ai:calls:'.now()->format('YmdH'), 6);
    Cache::put('ai:errors:'.now()->format('YmdH'), 4);

    $this->artisan('alerts:check')->expectsOutputToContain('błędów AI')->assertSuccessful();

    Http::assertSent(fn ($request) => str_contains($request['embeds'][0]['title'], 'AI'));
});
