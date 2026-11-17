<?php

use App\Models\Search;
use App\Models\User;

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
        ->expectsChoice('Portale', ['otodom'], ['otodom', 'olx'])
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
