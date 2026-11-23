<?php

namespace App\Filament\Pages\Auth;

use App\Models\User;
use Filament\Actions\Action;
use Filament\Auth\Pages\Login as BaseLogin;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Component;
use Filament\Schemas\Components\View;
use Filament\Schemas\Schema;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Validation\ValidationException;
use SensitiveParameter;

/**
 * Logowanie: najpierw passkey (bez loginu i hasła, przeglądarka sama wskazuje konto).
 * Login i hasło (z kodem 2FA) tylko dla konta bez passkey, żeby go dodać po pierwszym logowaniu.
 */
class Login extends BaseLogin
{
    private bool $hasPasskeyOnPasswordLogin = false;

    public function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                $this->getUsernameFormComponent(),
                $this->getPasswordFormComponent(),
                $this->getRememberFormComponent(),
            ]);
    }

    public function content(Schema $schema): Schema
    {
        return $schema
            ->components([
                View::make('filament.auth.passkey-login')
                    ->visible(fn (): bool => blank($this->userUndertakingMultiFactorAuthentication)),
                $this->getFormContentComponent(),
                $this->getMultiFactorChallengeFormContentComponent(),
            ]);
    }

    /**
     * Logowanie hasłem jako drugorzędne (szare), główny jest passkey.
     */
    protected function getAuthenticateFormAction(): Action
    {
        return parent::getAuthenticateFormAction()->color('gray')->label('Zaloguj hasłem');
    }

    protected function getUsernameFormComponent(): Component
    {
        return TextInput::make('username')
            ->label('Login')
            ->required()
            ->maxLength(50)
            ->autocomplete('username');
    }

    protected function getCredentialsFromFormData(#[SensitiveParameter] array $data): array
    {
        return [
            'username' => $data['username'],
            'password' => $data['password'],
        ];
    }

    /**
     * Sprawdzane po poprawnym haśle (w środku Timebox Filamenta). Konto z passkey loguje się tylko passkey.
     */
    protected function isUserAllowedToAccessPanel(Authenticatable $user): bool
    {
        if (config('passkeys.required') && $user instanceof User && $user->passkeys()->exists()) {
            $this->hasPasskeyOnPasswordLogin = true;

            return false;
        }

        return parent::isUserAllowedToAccessPanel($user);
    }

    protected function throwFailureValidationException(): never
    {
        throw ValidationException::withMessages([
            'data.username' => $this->hasPasskeyOnPasswordLogin
                ? 'To konto ma passkey, więc loguje się tylko nim. Użyj przycisku „Zaloguj passkey” powyżej.'
                : __('filament-panels::auth/pages/login.messages.failed'),
        ]);
    }
}
