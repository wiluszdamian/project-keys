<?php

namespace Database\Factories;

use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * @extends Factory<User>
 */
class UserFactory extends Factory
{
    /**
     * The current password being used by the factory.
     */
    protected static ?string $password;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => fake()->name(),
            'username' => fake()->unique()->userName(),
            'is_admin' => true,
            'email' => fake()->unique()->safeEmail(),
            'email_verified_at' => now(),
            'password' => static::$password ??= Hash::make('password'),
            'remember_token' => Str::random(10),
            // Panel wymaga 2FA. Bez sekretu każde wejście kończy się na stronie konfiguracji.
            'app_authentication_secret' => 'JBSWY3DPEHPK3PXP',
        ];
    }

    /**
     * Panel wymaga passkey: domyślnie konto ma jeden (bez prawdziwego klucza, wystarcza do testów panelu).
     */
    public function configure(): static
    {
        return $this->afterCreating(fn (User $user) => DB::table('passkeys')->insert([
            'authenticatable_id' => $user->id,
            'name' => 'test',
            'credential_id' => Str::random(32),
            'data' => '{}',
            'created_at' => now(),
            'updated_at' => now(),
        ]));
    }

    /**
     * Konto bez passkey (np. przed pierwszym logowaniem passkey).
     */
    public function withoutPasskey(): static
    {
        return $this->afterCreating(fn (User $user) => $user->passkeys()->delete());
    }

    /**
     * Konto, które jeszcze nie ustawiło 2FA.
     */
    public function withoutTwoFactor(): static
    {
        return $this->state(fn (array $attributes) => [
            'app_authentication_secret' => null,
            'app_authentication_recovery_codes' => null,
        ]);
    }

    /**
     * Indicate that the model's email address should be unverified.
     */
    public function unverified(): static
    {
        return $this->state(fn (array $attributes) => [
            'email_verified_at' => null,
        ]);
    }
}
