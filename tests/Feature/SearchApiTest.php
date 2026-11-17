<?php

use App\Models\Listing;
use App\Models\ListingAnalysis;
use App\Models\ScrapeRun;
use App\Models\Search;
use App\Models\User;

it('wymaga tokenu', function () {
    $this->getJson('/api/searches')->assertUnauthorized();
    $this->postJson('/api/searches', validSearchPayload())->assertUnauthorized();
});

it('przyjmuje prawdziwy token Bearer', function () {
    $token = User::factory()->create()->createToken('test')->plainTextToken;

    $this->withToken($token)->getJson('/api/searches')->assertOk();
});

it('tworzy wyszukiwanie', function () {
    actingAsOwner();

    $response = $this->postJson('/api/searches', validSearchPayload())
        ->assertCreated()
        ->assertJsonPath('data.name', 'Kraków wynajem')
        ->assertJsonPath('data.property_type', 'flat')
        ->assertJsonPath('data.portals', ['otodom', 'olx'])
        ->assertJsonPath('data.is_active', true)
        ->assertJsonPath('data.first_run_completed', false);

    expect(Search::find($response->json('data.id')))
        ->districts->toBe(['Krowodrza', 'Bronowice'])
        ->min_score_to_notify->toBe(7);
});

it('waliduje pola przy tworzeniu', function (array $payload, array $errors) {
    actingAsOwner();

    $this->postJson('/api/searches', $payload)
        ->assertUnprocessable()
        ->assertJsonValidationErrors($errors);
})->with([
    'brak wymaganych' => [[], ['name', 'deal_type', 'city', 'portals', 'notify_channels']],
    'zły typ transakcji' => [validSearchPayload(['deal_type' => 'lease']), ['deal_type']],
    'nieznany portal' => [validSearchPayload(['portals' => ['gumtree']]), ['portals.0']],
    'nieznany kanał' => [validSearchPayload(['notify_channels' => ['sms']]), ['notify_channels.0']],
    'cena min większa od max' => [validSearchPayload(['price_min' => 5000, 'price_max' => 3000]), ['price_max']],
    'metraż min większy od max' => [validSearchPayload(['area_min' => 80, 'area_max' => 40]), ['area_max']],
    'ocena poza zakresem' => [validSearchPayload(['min_score_to_notify' => 11]), ['min_score_to_notify']],
    'pusta lista portali' => [validSearchPayload(['portals' => []]), ['portals']],
]);

it('listuje wyszukiwania z liczbą ofert', function () {
    actingAsOwner();
    $search = Search::factory()->create();
    $search->listings()->attach(Listing::factory()->count(2)->create(), ['matched_at' => now()]);
    Search::factory()->create();

    $this->getJson('/api/searches')
        ->assertOk()
        ->assertJsonCount(2, 'data')
        ->assertJsonPath('data.0.listings_count', 2);
});

it('pokazuje szczegóły z ostatnim przebiegiem', function () {
    actingAsOwner();
    $search = Search::factory()->create();
    ScrapeRun::factory()->for($search)->create(['started_at' => now()->subHour(), 'portal' => 'olx']);
    ScrapeRun::factory()->for($search)->create(['started_at' => now()->subMinute(), 'portal' => 'otodom']);

    $this->getJson("/api/searches/{$search->id}")
        ->assertOk()
        ->assertJsonPath('data.listings_count', 0)
        ->assertJsonPath('data.last_scrape_run.portal', 'otodom');
});

it('zmienia wyszukiwanie', function () {
    actingAsOwner();
    $search = Search::factory()->create();

    $this->patchJson("/api/searches/{$search->id}", ['price_max' => 5000, 'portals' => ['olx']])
        ->assertOk()
        ->assertJsonPath('data.price_max', 5000)
        ->assertJsonPath('data.portals', ['olx'])
        ->assertJsonPath('data.city', 'Kraków');
});

it('przy zmianie porównuje min i max z zapisanymi wartościami', function () {
    actingAsOwner();
    $search = Search::factory()->create(['price_min' => 2000, 'price_max' => 4000]);

    $this->patchJson("/api/searches/{$search->id}", ['price_max' => 1500])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['price_max']);
});

it('usuwa wyszukiwanie', function () {
    actingAsOwner();
    $search = Search::factory()->create();

    $this->deleteJson("/api/searches/{$search->id}")->assertNoContent();

    expect(Search::count())->toBe(0);
});

it('zwraca 404 dla nieistniejącego wyszukiwania', function () {
    actingAsOwner();

    $this->getJson('/api/searches/999')->assertNotFound();
});

it('wstrzymuje i wznawia wyszukiwanie', function () {
    actingAsOwner();
    $search = Search::factory()->create();

    $this->postJson("/api/searches/{$search->id}/pause")->assertOk()->assertJsonPath('data.is_active', false);
    expect($search->fresh()->is_active)->toBeFalse();

    $this->postJson("/api/searches/{$search->id}/resume")->assertOk()->assertJsonPath('data.is_active', true);
    expect($search->fresh()->is_active)->toBeTrue();
});

it('listuje oferty wyszukiwania od najnowszych i po ocenie', function () {
    actingAsOwner();
    $search = Search::factory()->create();
    $other = Search::factory()->create();

    $old = Listing::factory()->create();
    $new = Listing::factory()->create();
    $noScore = Listing::factory()->create();
    $search->listings()->attach($old, ['matched_at' => now()->subDays(2)]);
    $search->listings()->attach($new, ['matched_at' => now()->subDay()]);
    $search->listings()->attach($noScore, ['matched_at' => now()]);
    $other->listings()->attach(Listing::factory()->create(), ['matched_at' => now()]);

    ListingAnalysis::factory()->create(['listing_id' => $old->id, 'search_id' => $search->id, 'score' => 9]);
    ListingAnalysis::factory()->create(['listing_id' => $new->id, 'search_id' => $search->id, 'score' => 5]);
    // Ocena tej samej oferty dla innego wyszukiwania nie może się pokazać.
    ListingAnalysis::factory()->create(['listing_id' => $new->id, 'search_id' => $other->id, 'score' => 10]);

    $this->getJson("/api/searches/{$search->id}/listings")
        ->assertOk()
        ->assertJsonCount(3, 'data')
        ->assertJsonPath('data.0.id', $noScore->id)
        ->assertJsonPath('data.2.id', $old->id);

    $this->getJson("/api/searches/{$search->id}/listings?sort=score")
        ->assertJsonPath('data.0.id', $old->id)
        ->assertJsonPath('data.1.id', $new->id)
        ->assertJsonPath('data.1.analyses.0.score', 5)
        ->assertJsonCount(1, 'data.1.analyses')
        ->assertJsonPath('data.2.id', $noScore->id);

    $this->getJson("/api/searches/{$search->id}/listings?min_score=6")
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.id', $old->id);
});

it('stronicuje oferty', function () {
    actingAsOwner();
    $search = Search::factory()->create();
    $search->listings()->attach(Listing::factory()->count(5)->create(), ['matched_at' => now()]);

    $this->getJson("/api/searches/{$search->id}/listings?per_page=2&page=3")
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('meta.total', 5);
});

it('ogranicza liczbę zapytań do 60 na minutę', function () {
    $token = User::factory()->create()->createToken('test')->plainTextToken;

    for ($i = 0; $i < 60; $i++) {
        $this->withToken($token)->getJson('/api/searches')->assertOk();
    }

    $this->withToken($token)->getJson('/api/searches')->assertTooManyRequests();
});
