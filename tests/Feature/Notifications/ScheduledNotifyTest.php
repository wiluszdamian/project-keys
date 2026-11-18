<?php

use App\Jobs\NotifyRunResultsJob;
use App\Jobs\SendDigestJob;
use App\Jobs\SendListingNotificationJob;
use App\Models\Listing;
use App\Models\ListingAnalysis;
use App\Models\Search;
use App\Notifications\Notifier;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;

const SCHEDULED_HOOK = 'https://discord.com/api/webhooks/2/xyz';

beforeEach(function () {
    config(['notify.discord.webhook_url' => SCHEDULED_HOOK]);
    $this->search = Search::factory()->create([
        'notify_channels' => ['discord'],
        'notify_mode' => 'every_hours',
        'notify_every_hours' => 3,
        'first_run_completed' => true,
    ]);
});

function scheduledMatch(Search $search, ?int $score): int
{
    $listing = Listing::factory()->create();
    $search->listings()->attach($listing, ['matched_at' => now()]);

    if ($score !== null) {
        ListingAnalysis::factory()->create(['listing_id' => $listing->id, 'search_id' => $search->id, 'score' => $score]);
    }

    return $listing->id;
}

function reason(Search $search, int $listingId): ?string
{
    return DB::table('search_listing')->where('search_id', $search->id)->where('listing_id', $listingId)->value('notify_skipped_reason');
}

it('w trybie zbiorczym oferty czekają zamiast iść od razu', function () {
    Queue::fake();
    $good = scheduledMatch($this->search, 8);
    $weak = scheduledMatch($this->search, 3);

    (new NotifyRunResultsJob($this->search, now()->subMinute()->toIso8601String(), false, analyzed: true))->handle(app(Notifier::class));

    Queue::assertNotPushed(SendListingNotificationJob::class);
    Queue::assertNotPushed(SendDigestJob::class);
    expect(reason($this->search, $good))->toBe('scheduled')
        ->and(reason($this->search, $weak))->toBe('below_threshold');
});

it('wysyła podsumowanie co ustawione godziny', function () {
    Http::fake([SCHEDULED_HOOK => Http::response('', 204)]);
    $this->search->forceFill(['notify_last_sent_at' => now()->subHours(2)])->save();
    $good = scheduledMatch($this->search, 8);
    DB::table('search_listing')->where('listing_id', $good)->update(['notify_skipped_reason' => 'scheduled']);

    $this->artisan('notify:send-scheduled')->assertSuccessful();
    Http::assertNothingSent();

    $this->travel(61)->minutes();
    $this->artisan('notify:send-scheduled')->assertSuccessful();

    Http::assertSentCount(1);
    Http::assertSent(fn ($request) => str_contains($request['embeds'][0]['title'], 'Podsumowanie z ostatnich 3 h'));
    expect(DB::table('search_listing')->where('listing_id', $good)->first())
        ->notified_at->not->toBeNull()
        ->notify_skipped_reason->toBeNull();

    // Kolejne wywołanie bez nowych ofert nic nie wysyła.
    $this->artisan('notify:send-scheduled')->assertSuccessful();
    Http::assertSentCount(1);
});

it('raz dziennie wysyła o ustawionej godzinie', function () {
    Http::fake([SCHEDULED_HOOK => Http::response('', 204)]);
    $this->travelTo(Carbon::parse('2026-11-18 19:00'));
    $this->search->update(['notify_mode' => 'daily', 'notify_daily_at' => '20:00']);
    $good = scheduledMatch($this->search, 7);
    DB::table('search_listing')->where('listing_id', $good)->update(['notify_skipped_reason' => 'scheduled']);

    $this->artisan('notify:send-scheduled');
    Http::assertNothingSent();

    $this->travelTo(Carbon::parse('2026-11-18 20:00'));
    $this->artisan('notify:send-scheduled');
    Http::assertSent(fn ($request) => str_contains($request['embeds'][0]['title'], 'Podsumowanie dnia'));

    $this->travelTo(Carbon::parse('2026-11-18 22:00'));
    $other = scheduledMatch($this->search, 9);
    DB::table('search_listing')->where('listing_id', $other)->update(['notify_skipped_reason' => 'scheduled']);
    $this->artisan('notify:send-scheduled');
    Http::assertSentCount(1);
});

it('wyłączone powiadomienia: oferta zostaje tylko w panelu', function () {
    Queue::fake();
    $this->search->update(['notify_enabled' => false]);
    $id = scheduledMatch($this->search, 9);

    (new NotifyRunResultsJob($this->search, now()->subMinute()->toIso8601String(), false, analyzed: true))->handle(app(Notifier::class));

    Queue::assertNothingPushed();
    expect(reason($this->search, $id))->toBe('notifications_off');
});
