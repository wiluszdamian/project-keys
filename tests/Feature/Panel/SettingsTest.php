<?php

use App\Filament\Pages\Settings;
use App\Models\User;
use App\Support\AppSettings;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;

beforeEach(function () {
    $this->actingAs(User::factory()->create());
    Filament::setCurrentPanel('admin');
});

it('zapisuje tylko zmienione ustawienia i od razu je stosuje', function () {
    Livewire::test(Settings::class)
        ->fillForm([
            'scrape_interval_minutes' => 30,
            'ai_enabled' => false,
            'otodom_locations' => config('scrapers.otodom.locations') + ['lancut' => 'podkarpackie/lancucki/lancut/lancut'],
        ])
        ->call('save')
        ->assertHasNoFormErrors();

    expect(config('scrapers.interval_minutes'))->toBe(30)
        ->and(config('scoring.enabled'))->toBeFalse()
        ->and(config('scrapers.otodom.locations.lancut'))->toBe('podkarpackie/lancucki/lancut/lancut')
        ->and(DB::table('settings')->pluck('key')->sort()->values()->all())
        ->toBe(['ai_enabled', 'otodom_locations', 'scrape_interval_minutes']);
});

it('ustawienia z bazy działają po ponownym uruchomieniu aplikacji', function () {
    DB::table('settings')->insert(['key' => 'scraper_max_pages', 'value' => json_encode(5)]);
    Cache::forget(AppSettings::CACHE_KEY);

    AppSettings::apply();

    expect(config('scrapers.max_pages'))->toBe(5);
});

it('szyfruje sekrety i nie pokazuje ich w formularzu', function () {
    Livewire::test(Settings::class)
        ->fillForm(['discord_webhook_url' => 'https://discord.com/api/webhooks/9/sekret'])
        ->call('save')
        ->assertHasNoFormErrors()
        ->assertFormSet(['discord_webhook_url' => null]);

    $stored = DB::table('settings')->where('key', 'discord_webhook_url')->value('value');

    expect($stored)->not->toContain('sekret')
        ->and(config('notify.discord.webhook_url'))->toBe('https://discord.com/api/webhooks/9/sekret');

    // Pusty sekret przy kolejnym zapisie nie kasuje zapisanego.
    Livewire::test(Settings::class)->call('save');
    expect(config('notify.discord.webhook_url'))->toBe('https://discord.com/api/webhooks/9/sekret');
});

it('zapis restartuje workery', function () {
    Cache::forget('illuminate:queue:restart');

    Livewire::test(Settings::class)->fillForm(['scraper_max_pages' => 2])->call('save');

    expect(Cache::get('illuminate:queue:restart'))->not->toBeNull();
});
