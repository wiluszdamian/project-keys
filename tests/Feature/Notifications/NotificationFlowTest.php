<?php

use App\Events\ListingPriceChanged;
use App\Events\PortalBlockedRepeatedly;
use App\Jobs\NotifyRunResultsJob;
use App\Jobs\SendDigestJob;
use App\Jobs\SendListingNotificationJob;
use App\Models\Listing;
use App\Models\ScrapeRun;
use App\Models\Search;
use App\Notifications\Notifier;
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
