<?php

use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\DB;

beforeEach(function () {
    Filament::setCurrentPanel('admin');
});

it('bez ustawionego 2FA panel wysyła do konfiguracji', function () {
    $this->actingAs(User::factory()->withoutTwoFactor()->create());

    $response = $this->get('/admin');

    $response->assertRedirect();
    expect($response->headers->get('Location'))->toContain('multi-factor');
});

it('z ustawionym 2FA panel działa', function () {
    $this->actingAs(User::factory()->create());

    $this->get('/admin')->assertOk();
});

it('logowanie z 2FA prosi o kod z aplikacji', function () {
    $this->get('/admin/login')->assertOk();

    expect(Filament::getCurrentPanel()->isMultiFactorAuthenticationRequired())->toBeTrue()
        ->and(array_keys(Filament::getCurrentPanel()->getMultiFactorAuthenticationProviders()))->toBe(['app']);
});

it('user:reset-2fa wyłącza 2FA dla konta', function () {
    $user = User::factory()->create(['app_authentication_recovery_codes' => ['a', 'b']]);

    $this->artisan('user:reset-2fa', ['username' => $user->username])->assertSuccessful();

    $user->refresh();
    expect($user->app_authentication_secret)->toBeNull()
        ->and($user->app_authentication_recovery_codes)->toBeNull();

    $this->artisan('user:reset-2fa', ['username' => 'nikt'])->assertFailed();
});

it('sekret 2FA jest zaszyfrowany w bazie', function () {
    $user = User::factory()->create();

    $raw = DB::table('users')->where('id', $user->id)->value('app_authentication_secret');

    expect($raw)->not->toBe('JBSWY3DPEHPK3PXP')
        ->and($user->app_authentication_secret)->toBe('JBSWY3DPEHPK3PXP');
});
