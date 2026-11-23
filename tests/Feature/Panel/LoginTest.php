<?php

use App\Filament\Pages\Auth\EditProfile;
use App\Filament\Pages\Auth\Login;
use App\Models\User;
use Filament\Facades\Filament;
use Livewire\Livewire;

beforeEach(function () {
    Filament::setCurrentPanel('admin');
});

it('strona logowania ma passkey i login zamiast e-maila', function () {
    $this->get('/admin/login')
        ->assertOk()
        ->assertSee('Zaloguj passkey')
        ->assertSee('Login')
        ->assertDontSee('Email address');
});

it('konto bez passkey loguje się loginem i hasłem, potem prosi o kod 2FA', function () {
    User::factory()->withoutPasskey()->create(['username' => 'damian', 'password' => 'tajne-haslo-123']);

    Livewire::test(Login::class)
        ->fillForm(['username' => 'damian', 'password' => 'tajne-haslo-123'])
        ->call('authenticate')
        ->assertHasNoFormErrors()
        ->assertSet('userUndertakingMultiFactorAuthentication', fn ($value) => filled($value));
});

it('konto z passkey nie zaloguje się hasłem', function () {
    User::factory()->create(['username' => 'damian', 'password' => 'tajne-haslo-123']);

    Livewire::test(Login::class)
        ->fillForm(['username' => 'damian', 'password' => 'tajne-haslo-123'])
        ->call('authenticate')
        ->assertHasFormErrors(['username'])
        ->assertSee('loguje się tylko nim');
});

it('złe hasło i konto bez dostępu do panelu dają ten sam błąd', function () {
    User::factory()->withoutPasskey()->create(['username' => 'damian', 'password' => 'tajne-haslo-123']);
    User::factory()->withoutPasskey()->create(['username' => 'api', 'password' => 'tajne-haslo-123', 'is_admin' => false]);

    Livewire::test(Login::class)
        ->fillForm(['username' => 'damian', 'password' => 'zle'])
        ->call('authenticate')
        ->assertHasFormErrors(['username']);

    Livewire::test(Login::class)
        ->fillForm(['username' => 'api', 'password' => 'tajne-haslo-123'])
        ->call('authenticate')
        ->assertHasFormErrors(['username']);
});

it('konto bez passkey po zalogowaniu trafia do profilu, żeby go dodać', function () {
    $this->actingAs(User::factory()->withoutPasskey()->create());

    $this->get('/admin/listings')->assertRedirect('/admin/profile');
    $this->get('/admin/profile')->assertOk()->assertSee('Passkeys');
});

it('konto z passkey ma dostęp do panelu, konto nie-administratora nie', function () {
    $this->actingAs(User::factory()->create());
    $this->get('/admin/listings')->assertOk();

    $this->actingAs(User::factory()->create(['is_admin' => false]));
    $this->get('/admin/listings')->assertForbidden();
});

it('profil zmienia login', function () {
    $user = User::factory()->create(['username' => 'damian']);
    $this->actingAs($user);

    Livewire::test(EditProfile::class)
        ->fillForm(['username' => 'damian.w'])
        ->call('save')
        ->assertHasNoFormErrors();

    expect($user->fresh()->username)->toBe('damian.w');
});

it('opcje logowania passkey są dostępne dla przeglądarki', function () {
    $this->getJson('/passkeys/authentication-options')->assertOk()->assertJsonStructure(['challenge']);
});

it('user:create zakłada administratora, a user:reset-passkeys usuwa jego passkeys', function () {
    $this->artisan('user:create', ['username' => 'nowy', '--admin' => true, '--password' => 'bardzo-dlugie-haslo'])->assertSuccessful();

    $user = User::where('username', 'nowy')->sole();
    expect($user->is_admin)->toBeTrue();

    $this->artisan('user:create', ['username' => 'nowy', '--password' => 'bardzo-dlugie-haslo'])->assertFailed();

    $admin = User::factory()->create(['username' => 'damian']);
    expect($admin->passkeys()->count())->toBe(1);
    $this->artisan('user:reset-passkeys', ['username' => 'damian'])->assertSuccessful();
    expect($admin->passkeys()->count())->toBe(0);
});
