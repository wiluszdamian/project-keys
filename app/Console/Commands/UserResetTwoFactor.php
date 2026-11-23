<?php

namespace App\Console\Commands;

use App\Models\User;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('user:reset-2fa {username : Login konta w panelu}')]
#[Description('Wyłącza 2FA dla konta (np. po utracie telefonu i kodów zapasowych). Przy następnym logowaniu panel poprosi o nową konfigurację.')]
class UserResetTwoFactor extends Command
{
    public function handle(): int
    {
        $user = User::where('username', $this->argument('username'))->first();

        if ($user === null) {
            $this->error('Nie ma konta o takim loginie.');

            return self::FAILURE;
        }

        $user->saveAppAuthenticationSecret(null);
        $user->saveAppAuthenticationRecoveryCodes(null);

        $this->info("2FA wyłączone dla {$user->username}. Ustaw je ponownie po zalogowaniu.");

        return self::SUCCESS;
    }
}
