<?php

namespace App\Console\Commands;

use App\Models\User;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('user:reset-passkeys {username : Login konta w panelu}')]
#[Description('Usuwa passkeys konta (np. po zgubieniu telefonu). Potem logowanie loginem, hasłem i kodem 2FA i dodanie nowego passkey.')]
class UserResetPasskeys extends Command
{
    public function handle(): int
    {
        $user = User::where('username', $this->argument('username'))->first();

        if (! $user) {
            $this->error('Nie ma konta o takim loginie.');

            return self::FAILURE;
        }

        $count = $user->passkeys()->delete();
        $this->info("Usunięte passkeys konta {$user->username}: {$count}. Zaloguj się loginem, hasłem i kodem 2FA, a potem dodaj nowy passkey w profilu.");

        return self::SUCCESS;
    }
}
