<?php

use App\Events\ListingPriceChanged;
use App\Jobs\NotifyRunResultsJob;
use App\Jobs\SendDigestJob;
use App\Jobs\SendListingNotificationJob;
use App\Models\Listing;
use App\Models\ListingAnalysis;
use App\Models\Search;
use App\Models\User;
use App\Notifications\Notifier;
use App\Notifications\PanelNotifier;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;

const GROUP_HOOK = 'https://discord.com/api/webhooks/3/group';

beforeEach(function () {
    config([
        'notify.discord.webhook_url' => GROUP_HOOK,
        'notify.groups.rent' => ['schedule' => 'daily', 'every_hours' => 6, 'daily_at' => '20:00'],
    ]);
    $this->user = User::factory()->create();
    $this->first = Search::factory()->create(['name' => 'Rzeszów 3 pokoje', 'deal_type' => 'rent', 'notify_channels' => ['discord'], 'first_run_completed' => true]);
    $this->second = Search::factory()->create(['name' => 'Projektant', 'deal_type' => 'rent', 'notify_channels' => ['discord'], 'first_run_completed' => false]);
});

function groupMatch(Search $search, Listing $listing, ?int $score): void
{
    $search->listings()->syncWithoutDetaching([$listing->id => ['matched_at' => now()]]);

    if ($score !== null) {
        ListingAnalysis::factory()->create(['listing_id' => $listing->id, 'search_id' => $search->id, 'score' => $score]);
    }
}

it('przy kanale zbiorczym oferty czekają, także w pierwszym przebiegu, a panel dostaje powiadomienie od razu', function () {
    Queue::fake([SendDigestJob::class, SendListingNotificationJob::class]);
    $listing = Listing::factory()->create(['deal_type' => 'rent']);
    groupMatch($this->second, $listing, 8);

    (new NotifyRunResultsJob($this->second, now()->subMinute()->toIso8601String(), true, analyzed: true))
        ->handle(app(Notifier::class), app(PanelNotifier::class));

    Queue::assertNotPushed(SendDigestJob::class);
    Queue::assertNotPushed(SendListingNotificationJob::class);
    expect(DB::table('search_listing')->value('notify_skipped_reason'))->toBe('scheduled')
        ->and($this->user->notifications()->count())->toBe(1)
        ->and($this->user->notifications()->first()->data['title'])->toBe('Projektant: nowa oferta');
});

it('wysyła jedno podsumowanie kanału o ustawionej godzinie z ofertami ze wszystkich wyszukiwań', function () {
    Http::fake([GROUP_HOOK => Http::response('', 204)]);
    $this->travelTo(Carbon::parse('2026-11-18 19:00'));
    $shared = Listing::factory()->create(['deal_type' => 'rent', 'title' => 'Wspólna oferta']);
    $other = Listing::factory()->create(['deal_type' => 'rent', 'title' => 'Druga oferta']);
    groupMatch($this->first, $shared, 7);
    groupMatch($this->second, $shared, 9);
    groupMatch($this->second, $other, 6);
    DB::table('search_listing')->update(['notify_skipped_reason' => 'scheduled']);

    $this->artisan('notify:send-scheduled');
    Http::assertNothingSent();

    $this->travelTo(Carbon::parse('2026-11-18 20:01'));
    $this->artisan('notify:send-scheduled');

    Http::assertSentCount(1);
    Http::assertSent(function ($request) {
        $embed = $request['embeds'][0];

        return $embed['title'] === 'Wynajem: 2 nowych ofert z oceną od progu'
            && str_contains($embed['author']['name'], 'Podsumowanie dnia')
            && str_contains($embed['description'], '`9/10` **[Wspólna oferta]')
            && str_contains($embed['description'], 'Rzeszów 3 pokoje, Projektant');
    });
    expect(DB::table('search_listing')->whereNull('notified_at')->count())->toBe(0);

    $this->travelTo(Carbon::parse('2026-11-18 22:00'));
    $this->artisan('notify:send-scheduled');
    Http::assertSentCount(1);
});

it('obniżki cen czekają na podsumowanie kanału, a do panelu idą od razu', function () {
    Http::fake([GROUP_HOOK => Http::response('', 204)]);
    $this->travelTo(Carbon::parse('2026-11-18 20:30'));
    $listing = Listing::factory()->create(['deal_type' => 'rent', 'title' => 'Tańsze mieszkanie']);
    $this->first->listings()->attach($listing, ['matched_at' => now(), 'notified_at' => now()->subDay()]);

    ListingPriceChanged::dispatch($this->first, $listing, 3000, 2700);

    Http::assertNothingSent();
    expect($this->user->notifications()->first()->data['title'])->toStartWith('Obniżka ceny');

    $this->artisan('notify:send-scheduled');

    Http::assertSent(fn ($request) => str_contains($request['embeds'][0]['description'], '📉 Obniżki cen')
        && str_contains($request['embeds'][0]['description'], '3 000 zł → 2 700 zł'));
});

it('API pokazuje i zmienia zbiorcze powiadomienia kanałów', function () {
    actingAsOwner();

    $this->getJson('/api/notification-settings')
        ->assertOk()
        ->assertJsonPath('data.rent.schedule', 'daily')
        ->assertJsonPath('data.sale.schedule', 'per_search');

    $this->putJson('/api/notification-settings', ['sale' => ['schedule' => 'every_hours', 'every_hours' => 4]])
        ->assertOk()
        ->assertJsonPath('data.sale.schedule', 'every_hours')
        ->assertJsonPath('data.sale.every_hours', 4);

    expect(config('notify.groups.sale.schedule'))->toBe('every_hours');

    $this->putJson('/api/notification-settings', ['rent' => ['schedule' => 'daily']])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('rent.daily_at');
});
