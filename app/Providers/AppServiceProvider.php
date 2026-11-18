<?php

namespace App\Providers;

use App\Support\AppSettings;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        //
    }

    public function boot(): void
    {
        // Ustawienia z panelu nadpisują config. Workery czytają je przy starcie, zapis w panelu je restartuje.
        AppSettings::apply();

        // 60 zapytań na minutę na token (bez tokenu: na adres IP).
        RateLimiter::for('api', function (Request $request) {
            $key = $request->user()?->currentAccessToken()?->id ?? $request->ip();

            return Limit::perMinute(60)->by('api:'.$key);
        });

        // Wywołania modelu AI: AI_MAX_PER_MINUTE na minutę dla całej aplikacji.
        RateLimiter::for('ai', fn () => Limit::perMinute(config('scoring.max_per_minute'))->by('ai'));
    }
}
