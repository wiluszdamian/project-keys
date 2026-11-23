<?php

use App\Jobs\AnalyzeListingJob;
use App\Models\Listing;
use App\Models\ListingAnalysis;
use App\Models\Search;
use App\Models\User;
use Illuminate\Support\Facades\Queue;

it('tworzy token api', function () {
    $this->artisan('api:token', ['name' => 'laptop'])
        ->expectsOutputToContain('Nowy token')
        ->assertSuccessful();

    $user = User::where('email', 'owner@localhost')->sole();
    expect($user->tokens()->pluck('name')->all())->toBe(['laptop']);

    // Drugi token trafia do tego samego użytkownika.
    $this->artisan('api:token')->assertSuccessful();
    expect(User::count())->toBe(1)->and($user->tokens()->count())->toBe(2);
});

it('tworzy wyszukiwanie z komendy', function () {
    $this->artisan('search:create')
        ->expectsQuestion('Nazwa', 'Warszawa sprzedaż')
        ->expectsQuestion('Typ transakcji', 'sale')
        ->expectsQuestion('Miasto', 'Warszawa')
        ->expectsQuestion('Dzielnice po przecinku (puste = całe miasto)', 'Mokotów, Wola')
        ->expectsQuestion('Cena od (PLN, puste = bez limitu)', '')
        ->expectsQuestion('Cena do (PLN, puste = bez limitu)', '900000')
        ->expectsQuestion('Metraż od (m2)', '40')
        ->expectsQuestion('Metraż do (m2)', '')
        ->expectsQuestion('Pokoje od', '2')
        ->expectsQuestion('Pokoje do', '')
        ->expectsChoice('Portale', ['otodom'], config('scrapers.portals'))
        ->expectsChoice('Powiadomienia', ['discord'], ['telegram', 'discord'])
        ->expectsQuestion('Preferencje dla AI (np. balkon, blisko metra, bez agencji)', '')
        ->expectsQuestion('Minimalna ocena do powiadomienia (1-10)', '7')
        ->expectsOutputToContain('Utworzono wyszukiwanie')
        ->assertSuccessful();

    expect(Search::sole())
        ->deal_type->value->toBe('sale')
        ->districts->toBe(['Mokotów', 'Wola'])
        ->price_min->toBeNull()
        ->price_max->toBe(900000)
        ->portals->toBe(['otodom'])
        ->notify_channels->toBe(['discord'])
        ->ai_preferences->toBeNull()
        ->min_score_to_notify->toBe(7);
});

it('listuje wyszukiwania w komendzie', function () {
    Search::factory()->create(['name' => 'Moje wyszukiwanie']);

    $this->artisan('search:list')
        ->expectsOutputToContain('Moje wyszukiwanie')
        ->assertSuccessful();
});

it('wstrzymuje i wznawia z komendy', function () {
    $search = Search::factory()->create();

    $this->artisan('search:pause', ['id' => $search->id])->assertSuccessful();
    expect($search->fresh()->is_active)->toBeFalse();

    $this->artisan('search:resume', ['id' => $search->id])->assertSuccessful();
    expect($search->fresh()->is_active)->toBeTrue();

    $this->artisan('search:pause', ['id' => 999])->assertFailed();
});

it('search:refilter odłącza oferty, które nie pasują do nowych filtrów', function () {
    $search = Search::factory()->create(['deal_type' => 'sale', 'price_min' => null, 'price_max' => 550000, 'area_min' => null, 'area_max' => null, 'rooms_min' => 3, 'rooms_max' => 4, 'market' => 'secondary', 'move_in_ready' => true]);
    $ok = Listing::factory()->create(['deal_type' => 'sale', 'price' => 500000, 'rooms' => 3, 'description' => 'Umeblowane.']);
    $tooExpensive = Listing::factory()->create(['deal_type' => 'sale', 'price' => 560000, 'rooms' => 3]);
    $primary = Listing::factory()->create(['deal_type' => 'sale', 'price' => 500000, 'rooms' => 3, 'raw' => ['market' => 'primary']]);
    $unfurnished = Listing::factory()->create(['deal_type' => 'sale', 'price' => 500000, 'rooms' => 4, 'description' => 'Do remontu.']);
    $search->listings()->attach([$ok->id, $tooExpensive->id, $primary->id, $unfurnished->id], ['matched_at' => now()]);
    ListingAnalysis::factory()->create(['search_id' => $search->id, 'listing_id' => $primary->id]);

    $this->artisan('search:refilter', ['search' => [$search->id], '--dry-run' => true])->expectsOutputToContain('3 do odłączenia')->assertSuccessful();
    expect($search->listings()->count())->toBe(4);

    $this->artisan('search:refilter', ['search' => [$search->id]])->assertSuccessful();
    expect($search->listings()->pluck('listings.id')->all())->toBe([$ok->id])
        ->and(ListingAnalysis::count())->toBe(0);
});

it('listings:score-missing zleca ocenę tylko aktywnym ofertom bez oceny', function () {
    Queue::fake();
    config(['ai.providers.opencode.key' => 'test-key']);
    $search = Search::factory()->create();
    $missing = Listing::factory()->create();
    $scored = Listing::factory()->create();
    $old = Listing::factory()->create(['last_seen_at' => now()->subDays(10)]);
    $search->listings()->attach([$missing->id, $scored->id, $old->id], ['matched_at' => now()]);
    ListingAnalysis::factory()->create(['search_id' => $search->id, 'listing_id' => $scored->id]);

    $this->artisan('listings:score-missing')->expectsOutputToContain('Razem: 1.')->assertSuccessful();

    Queue::assertPushed(AnalyzeListingJob::class, 1);
    Queue::assertPushed(AnalyzeListingJob::class, fn ($job) => $job->listing->is($missing));
});
