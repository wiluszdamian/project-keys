<?php

namespace App\Http\Middleware;

use App\Models\User;
use Closure;
use Filament\Notifications\Notification;
use Illuminate\Http\Request;
use Livewire\Livewire;
use Symfony\Component\HttpFoundation\Response;

/**
 * Konto bez passkey (zalogowane hasłem i kodem 2FA) może wejść tylko do profilu, żeby dodać passkey.
 */
class RequirePasskey
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if (! config('passkeys.required')
            || ! $user instanceof User
            || $user->passkeys()->exists()
            || $request->routeIs('filament.admin.auth.*')
            || Livewire::isLivewireRequest()) {
            return $next($request);
        }

        Notification::make()
            ->warning()
            ->title('Dodaj passkey')
            ->body('Panel wymaga passkey (klucza dostępu). Wpisz nazwę, np. „Telefon”, i kliknij Utwórz w sekcji Klucze dostępu poniżej. Kolejne logowania będą tylko passkey.')
            ->persistent()
            ->send();

        return redirect()->route('filament.admin.auth.profile');
    }
}
