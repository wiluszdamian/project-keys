<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

return new class extends Migration
{
    // Logowanie loginem zamiast e-maila, panel tylko dla administratorów (prośba właściciela).
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('username', 50)->nullable()->unique()->after('name');
            $table->boolean('is_admin')->default(false)->after('username');
            $table->string('email')->nullable()->change();
        });

        // Login z części e-maila przed @. Administratorem zostaje konto, które używało panelu (ma 2FA).
        foreach (DB::table('users')->orderBy('id')->get() as $user) {
            $base = Str::of((string) Str::before((string) $user->email, '@'))->ascii()->lower()->replaceMatches('/[^a-z0-9._-]/', '')->toString() ?: "user{$user->id}";
            $username = $base;

            for ($i = 2; DB::table('users')->where('username', $username)->exists(); $i++) {
                $username = "{$base}{$i}";
            }

            DB::table('users')->where('id', $user->id)->update([
                'username' => $username,
                'is_admin' => $user->app_authentication_secret !== null,
            ]);
        }
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn(['username', 'is_admin']);
        });
    }
};
