{{-- Logowanie passkey (spatie/laravel-passkeys). Przeglądarka sama pokazuje passkeys dla tej domeny. --}}
<div class="pk-passkey-login">
    <x-authenticate-passkey redirect="{{ filament()->getCurrentOrDefaultPanel()->getUrl() }}">
        <x-filament::button icon="heroicon-o-finger-print" size="lg" class="w-full">
            Zaloguj passkey
        </x-filament::button>
    </x-authenticate-passkey>

    <p class="pk-passkey-login__hint">
        Pierwsze logowanie albo konto bez passkey: login i hasło poniżej, potem dodasz passkey w profilu.
    </p>
</div>

<style>
    .pk-passkey-login { display: flex; flex-direction: column; gap: .75rem; }
    .pk-passkey-login__hint { font-size: .8125rem; color: var(--gray-500); text-align: center; border-top: 1px solid var(--gray-200); padding-top: .75rem; }
    .dark .pk-passkey-login__hint { border-color: rgb(255 255 255 / .1); }
</style>
