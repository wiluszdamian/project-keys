<?php

namespace App\Console\Commands;

use App\Models\User;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Str;

#[Signature('api:token {name=cli : Nazwa tokenu, np. telefon albo laptop}')]
#[Description('Tworzy token API dla właściciela')]
class MakeApiToken extends Command
{
    public function handle(): int
    {
        // Konto tylko do API: hasło losowe, logowanie tokenem. Nie jest administratorem, więc nie wejdzie do panelu.
        $user = User::firstOrCreate(
            ['email' => 'owner@localhost'],
            ['name' => 'Właściciel', 'username' => 'api', 'password' => Str::random(40), 'is_admin' => false],
        );

        $token = $user->createToken($this->argument('name'));

        $this->info('Nowy token (pokazany tylko raz):');
        $this->line($token->plainTextToken);

        return self::SUCCESS;
    }
}
