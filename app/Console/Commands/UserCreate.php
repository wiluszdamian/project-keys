<?php

namespace App\Console\Commands;

use App\Models\User;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Validator;

#[Signature('user:create {username : Login, małe litery i cyfry} {--name= : Wyświetlana nazwa} {--admin : Dostęp do panelu} {--password= : Hasło (bez tej opcji pyta)}')]
#[Description('Zakłada konto. Do panelu wchodzi tylko administrator: pierwsze logowanie loginem, hasłem i 2FA, potem passkey.')]
class UserCreate extends Command
{
    public function handle(): int
    {
        $data = [
            'username' => $this->argument('username'),
            'password' => $this->option('password') ?? $this->secret('Hasło (co najmniej 12 znaków)'),
        ];

        $validator = Validator::make($data, [
            'username' => ['required', 'max:50', 'regex:/^[a-z0-9._-]+$/', 'unique:users,username'],
            'password' => ['required', 'min:12'],
        ]);

        if ($validator->fails()) {
            foreach ($validator->errors()->all() as $error) {
                $this->error($error);
            }

            return self::FAILURE;
        }

        $user = User::create([
            'username' => $data['username'],
            'name' => $this->option('name') ?: $data['username'],
            'password' => $data['password'],
            'is_admin' => (bool) $this->option('admin'),
        ]);

        $this->info("Konto {$user->username} założone".($user->is_admin ? ' (administrator).' : '.'));

        return self::SUCCESS;
    }
}
