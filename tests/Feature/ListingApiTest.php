<?php

use App\Models\Listing;
use App\Models\ListingAnalysis;
use App\Models\ListingPriceChange;
use App\Models\ScrapeRun;
use App\Models\Search;

it('pokazuje ofertę z historią cen i ocenami', function () {
    actingAsOwner();
    $listing = Listing::factory()->create(['description' => 'Pełny opis', 'raw' => ['secret' => 1]]);
    ListingPriceChange::create(['listing_id' => $listing->id, 'old_price' => 3500, 'new_price' => 3200, 'changed_at' => now()]);
    ListingAnalysis::factory()->create(['listing_id' => $listing->id, 'score' => 8]);

    $this->getJson("/api/listings/{$listing->id}")
        ->assertOk()
        ->assertJsonPath('data.description', 'Pełny opis')
        ->assertJsonPath('data.price_changes.0.new_price', 3200)
        ->assertJsonPath('data.analyses.0.score', 8)
        ->assertJsonMissingPath('data.raw');
});

it('listuje przebiegi z filtrami', function () {
    actingAsOwner();
    $search = Search::factory()->create();
    ScrapeRun::factory()->for($search)->create(['portal' => 'otodom', 'status' => 'ok']);
    ScrapeRun::factory()->for($search)->create(['portal' => 'olx', 'status' => 'blocked']);
    ScrapeRun::factory()->for($search)->create(['portal' => 'olx', 'status' => 'ok']);

    $this->getJson('/api/scrape-runs')->assertOk()->assertJsonCount(3, 'data');
    $this->getJson('/api/scrape-runs?portal=olx')->assertJsonCount(2, 'data');
    $this->getJson('/api/scrape-runs?portal=olx&status=blocked')
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.status', 'blocked');
    $this->getJson('/api/scrape-runs?status=nope')->assertUnprocessable();
});
