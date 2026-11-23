<?php

namespace App\Support;

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Ustawienia z panelu (tabela settings). Nadpisują config, a ten bierze wartości domyślne z .env.
 * Pusta wartość albo równa domyślnej = brak wpisu, działa wartość z .env.
 * Sekrety są zaszyfrowane kluczem APP_KEY.
 */
class AppSettings
{
    public const CACHE_KEY = 'app-settings';

    /**
     * Wartości z config/.env sprzed nadpisania. Ustawienie równe domyślnemu nie trafia do bazy.
     *
     * @var array<string, mixed>
     */
    private static array $defaults = [];

    /**
     * Klucz ustawienia => [ścieżka w config, typ]. Typy: int, float, bool, string, secret, map.
     */
    public const DEFINITIONS = [
        'scrape_interval_minutes' => ['scrapers.interval_minutes', 'int'],
        'scraper_delay_ms' => ['scrapers.delay_ms', 'int'],
        'scraper_jitter_ms' => ['scrapers.jitter_ms', 'int'],
        'scraper_max_pages' => ['scrapers.max_pages', 'int'],
        'scraper_retries' => ['scrapers.retries', 'int'],
        'scraper_block_hours' => ['scrapers.block_hours', 'int'],
        'scraper_user_agent' => ['scrapers.user_agent', 'string'],
        'otodom_locations' => ['scrapers.otodom.locations', 'map'],
        'morizon_locations' => ['scrapers.morizon.locations', 'map'],
        'gratka_locations' => ['scrapers.gratka.locations', 'map'],
        'domiporta_locations' => ['scrapers.domiporta.locations', 'map'],
        'adresowo_locations' => ['scrapers.adresowo.locations', 'map'],

        'notify_digest_threshold' => ['notify.digest_threshold', 'int'],
        'notify_digest_size' => ['notify.digest_size', 'int'],
        'notify_price_drops' => ['notify.price_drops', 'bool'],
        'notify_alert_cooldown_hours' => ['notify.alert_cooldown_hours', 'int'],
        'notify_alerts_discord' => ['notify.alerts_discord', 'bool'],
        'notify_weekly_report' => ['notify.weekly_report', 'bool'],
        'notify_rent_schedule' => ['notify.groups.rent.schedule', 'string'],
        'notify_rent_every_hours' => ['notify.groups.rent.every_hours', 'int'],
        'notify_rent_daily_at' => ['notify.groups.rent.daily_at', 'string'],
        'notify_sale_schedule' => ['notify.groups.sale.schedule', 'string'],
        'notify_sale_every_hours' => ['notify.groups.sale.every_hours', 'int'],
        'notify_sale_daily_at' => ['notify.groups.sale.daily_at', 'string'],
        'discord_username' => ['notify.discord.username', 'string'],
        'discord_webhook_url' => ['notify.discord.webhook_url', 'secret'],
        'discord_webhook_url_rent' => ['notify.discord.webhook_url_rent', 'secret'],
        'discord_webhook_url_sale' => ['notify.discord.webhook_url_sale', 'secret'],

        'rcn_enabled' => ['rcn.enabled', 'bool'],
        'rcn_counties' => ['rcn.counties', 'string'],

        'mortgage_rates_date' => ['mortgage.rates_date', 'string'],
        'mortgage_index_name' => ['mortgage.index_name', 'string'],
        'mortgage_index_rate' => ['mortgage.index_rate', 'float'],
        'mortgage_nbp_rate_at_date' => ['mortgage.nbp_rate_at_date', 'float'],
        'mortgage_margin' => ['mortgage.margin', 'float'],
        'mortgage_fixed_rate' => ['mortgage.fixed_rate', 'float'],
        'mortgage_bridge_extra' => ['mortgage.bridge_extra', 'float'],
        'mortgage_bridge_months' => ['mortgage.bridge_months', 'int'],

        'ai_enabled' => ['scoring.enabled', 'bool'],
        'ai_max_per_minute' => ['scoring.max_per_minute', 'int'],
        'ai_reasoning_effort' => ['scoring.reasoning_effort', 'string'],
        'ai_description_limit' => ['scoring.description_limit', 'int'],
        'ai_model' => ['ai.providers.opencode.models.text.default', 'string'],
        'ai_base_url' => ['ai.providers.opencode.url', 'string'],
        'ai_api_key' => ['ai.providers.opencode.key', 'secret'],
    ];

    /**
     * Wczytuje zapisane ustawienia do config. Bez bazy (budowanie obrazu, pierwsza instalacja) nic nie robi.
     */
    public static function apply(): void
    {
        foreach (self::DEFINITIONS as $key => [$path]) {
            if (! array_key_exists($key, self::$defaults)) {
                self::$defaults[$key] = config($path);
            }
        }

        try {
            $stored = Cache::rememberForever(self::CACHE_KEY, fn () => DB::table('settings')->pluck('value', 'key')->all());
        } catch (Throwable) {
            return;
        }

        foreach ($stored as $key => $raw) {
            if (! isset(self::DEFINITIONS[$key])) {
                continue;
            }

            try {
                config([self::DEFINITIONS[$key][0] => self::decode($key, $raw)]);
            } catch (Throwable) {
                // Np. sekret zaszyfrowany innym APP_KEY: zostaje wartość z .env.
            }
        }
    }

    /**
     * Wartości do formularza: aktualny config. Sekrety puste, formularz pokazuje tylko, czy są ustawione.
     *
     * @return array<string, mixed>
     */
    public static function formValues(): array
    {
        $values = [];

        foreach (self::DEFINITIONS as $key => [$path, $type]) {
            $values[$key] = $type === 'secret' ? null : config($path);
        }

        return $values;
    }

    public static function isSecretSet(string $key): bool
    {
        return filled(config(self::DEFINITIONS[$key][0]));
    }

    public static function isStored(string $key): bool
    {
        return DB::table('settings')->where('key', $key)->exists();
    }

    /**
     * Zapisuje ustawienia z panelu i restartuje workery, żeby wzięły nowe wartości.
     *
     * @param  array<string, mixed>  $data
     * @param  list<string>  $clearSecrets  sekrety do usunięcia z bazy (wraca wartość z .env)
     */
    public static function save(array $data, array $clearSecrets = []): void
    {
        foreach (self::DEFINITIONS as $key => [, $type]) {
            if ($type === 'secret') {
                if (in_array($key, $clearSecrets, true)) {
                    DB::table('settings')->where('key', $key)->delete();
                } elseif (filled($data[$key] ?? null)) {
                    self::store($key, Crypt::encryptString((string) $data[$key]));
                }

                continue;
            }

            if (! array_key_exists($key, $data)) {
                continue;
            }

            $value = $data[$key];
            $default = self::$defaults[$key] ?? null;

            if ($value === null || $value === '' || $value === [] || self::cast($type, $value) == ($default === null ? null : self::cast($type, $default))) {
                DB::table('settings')->where('key', $key)->delete();

                continue;
            }

            self::store($key, json_encode(self::cast($type, $value)));
        }

        Cache::forget(self::CACHE_KEY);
        self::apply();
        Artisan::call('queue:restart');
    }

    private static function store(string $key, string $value): void
    {
        DB::table('settings')->updateOrInsert(['key' => $key], ['value' => $value, 'updated_at' => now(), 'created_at' => now()]);
    }

    private static function decode(string $key, string $raw): mixed
    {
        $type = self::DEFINITIONS[$key][1];

        return $type === 'secret' ? Crypt::decryptString($raw) : self::cast($type, json_decode($raw, true));
    }

    private static function cast(string $type, mixed $value): mixed
    {
        return match ($type) {
            'int' => (int) $value,
            'float' => (float) str_replace(',', '.', (string) $value),
            'bool' => (bool) $value,
            'map' => array_filter((array) $value, fn ($path, $city) => filled($city) && filled($path), ARRAY_FILTER_USE_BOTH),
            default => (string) $value,
        };
    }
}
