<?php

namespace App\Filament\Pages\Auth;

use Filament\Auth\Pages\EditProfile as BaseEditProfile;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Component;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;

/**
 * Profil: login zamiast e-maila. Passkeys (wtyczka filament-passkeys) i 2FA są pod formularzem.
 */
class EditProfile extends BaseEditProfile
{
    public function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                $this->getNameFormComponent(),
                $this->getUsernameFormComponent(),
                $this->getPasswordFormComponent(),
                $this->getPasswordConfirmationFormComponent(),
                $this->getCurrentPasswordFormComponent(),
            ]);
    }

    protected function getUsernameFormComponent(): Component
    {
        return TextInput::make('username')
            ->label('Login')
            ->required()
            ->maxLength(50)
            ->regex('/^[a-z0-9._-]+$/')
            ->validationMessages(['regex' => 'Login: małe litery, cyfry, kropka, myślnik i podkreślnik.'])
            ->unique(ignoreRecord: true);
    }

    /**
     * Obecne hasło tylko przy zmianie hasła (nie przy zmianie loginu).
     */
    protected function getCurrentPasswordFormComponent(): Component
    {
        return parent::getCurrentPasswordFormComponent()
            ->visible(fn (Get $get): bool => filled($get('password')));
    }
}
