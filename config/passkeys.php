<?php

use App\Models\User;
use App\Support\PasskeyCeremony;
use Spatie\LaravelPasskeys\Actions\FindPasskeyToAuthenticateAction;
use Spatie\LaravelPasskeys\Actions\GeneratePasskeyAuthenticationOptionsAction;
use Spatie\LaravelPasskeys\Actions\GeneratePasskeyRegisterOptionsAction;
use Spatie\LaravelPasskeys\Actions\StorePasskeyAction;
use Spatie\LaravelPasskeys\Models\Passkey;

// spatie/laravel-passkeys. Passkey jest przypisany do domeny z APP_URL (nieruchomosci.wilusz.tech).
return [
    'redirect_to_after_login' => '/admin',

    'actions' => [
        'generate_passkey_register_options' => GeneratePasskeyRegisterOptionsAction::class,
        'store_passkey' => StorePasskeyAction::class,
        'generate_passkey_authentication_options' => GeneratePasskeyAuthenticationOptionsAction::class,
        'find_passkey' => FindPasskeyToAuthenticateAction::class,
        'configure_ceremony_step_manager_factory' => PasskeyCeremony::class,
    ],

    'relying_party' => [
        'name' => 'project-keys',
        'id' => parse_url((string) env('APP_URL', 'http://localhost'), PHP_URL_HOST),
        'icon' => null,
    ],

    // Wymuszenie: konto bez passkey po zalogowaniu hasłem może wejść tylko do profilu, żeby go dodać.
    'required' => (bool) env('PASSKEYS_REQUIRED', true),

    'models' => [
        'passkey' => Passkey::class,
        'authenticatable' => User::class,
    ],
];
