<?php

use App\Jobs\RunSearchJob;
use App\Models\Search;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Queue;

it('dodaje do kolejki tylko wyszukiwania, na które przyszła pora', function () {
    Queue::fake();
    config(['scrapers.interval_minutes' => 20]);

    $never = Search::factory()->create(['last_run_at' => null]);
    $due = Search::factory()->create(['last_run_at' => now()->subMinutes(21)]);
    $almostDue = Search::factory()->create(['last_run_at' => now()->subMinutes(20)->addSeconds(10)]);
    $recent = Search::factory()->create(['last_run_at' => now()->subMinutes(5)]);
    $inactive = Search::factory()->inactive()->create(['last_run_at' => null]);

    $this->artisan('search:dispatch-due')->expectsOutputToContain('Dodano do kolejki: 3')->assertSuccessful();

    $pushed = Queue::pushed(RunSearchJob::class)->map(fn ($job) => $job->search->id)->sort()->values()->all();
    expect($pushed)->toBe(collect([$never->id, $due->id, $almostDue->id])->sort()->values()->all());
});

it('harmonogram uruchamia search:dispatch-due co minutę', function () {
    $event = collect(app(Schedule::class)->events())
        ->first(fn ($event) => str_contains($event->command ?? '', 'search:dispatch-due'));

    expect($event)->not->toBeNull()->and($event->expression)->toBe('* * * * *');
});

it('search:run dodaje przebieg do kolejki', function () {
    Queue::fake();
    $search = Search::factory()->create();

    $this->artisan('search:run', ['id' => $search->id])->assertSuccessful();

    Queue::assertPushedOn('scrape', RunSearchJob::class, fn ($job) => $job->search->is($search));
});

it('POST /searches/{id}/run dodaje przebieg do kolejki', function () {
    Queue::fake();
    actingAsOwner();
    $search = Search::factory()->create();

    $this->postJson("/api/searches/{$search->id}/run")->assertAccepted();

    Queue::assertPushed(RunSearchJob::class);
});
