<?php

use App\Enums\DealType;
use App\Models\Listing;
use App\Models\ListingAnalysis;
use App\Models\Search;
use App\Notifications\Discord\DiscordMessages;
use App\Notifications\Discord\DiscordWebhook;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Sleep;

const WEBHOOK = 'https://discord.com/api/webhooks/1/abc';

beforeEach(function () {
    Sleep::fake();
    config(['notify.discord.webhook_url' => WEBHOOK]);
});

it('buduje embed oferty bez oceny AI', function () {
    $listing = Listing::factory()->create([
        'portal' => 'olx', 'title' => '3 pokoje Drabinianka', 'price' => 2500, 'rent_extra' => 400,
        'area' => 56, 'price_per_m2' => 45, 'rooms' => 3, 'floor' => '2', 'district' => 'Drabinianka',
        'city' => 'Rzeszów', 'is_private_owner' => true, 'image_url' => 'https://img.test/1.jpg',
        'description' => 'Ładne mieszkanie z balkonem.',
    ]);
    $search = Search::factory()->create(['name' => 'Rzeszów 3 pok.']);

    $embed = app(DiscordMessages::class)->listing($listing, $search)['embeds'][0];

    expect($embed)
        ->title->toBe('3 pokoje Drabinianka')
        ->url->toBe($listing->url)
        ->color->toBe(0x23E5DB)
        ->description->toBe('Ładne mieszkanie z balkonem.')
        ->and($embed['image']['url'])->toBe('https://img.test/1.jpg')
        ->and($embed['footer']['text'])->toBe('Wyszukiwanie: Rzeszów 3 pok.')
        ->and(collect($embed['fields'])->pluck('value', 'name')->all())->toMatchArray([
            '💰 Najem' => '2 500 zł + 400 zł czynsz',
            '📐 Metraż' => "56 m²\n45 zł/m²",
            '🚪 Pokoje' => "3\npiętro 2",
            '📍 Lokalizacja' => 'Drabinianka, Rzeszów',
            '👤 Ogłoszeniodawca' => 'prywatne',
            '🔗 Portal' => 'OLX',
        ]);
});

it('z oceną AI pokazuje ocenę, plusy i uwagi', function () {
    $analysis = ListingAnalysis::factory()->create([
        'score' => 8, 'summary' => 'Dobra cena za metraż.', 'pros' => ['balkon', 'garaż'], 'red_flags' => ['parter'],
    ]);

    $embed = app(DiscordMessages::class)->listing($analysis->listing, $analysis->search, $analysis)['embeds'][0];

    expect($embed['title'])->toStartWith('8/10 · ')
        ->and($embed['color'])->toBe(0x2ECC71)
        ->and($embed['description'])->toBe("Dobra cena za metraż.\n\n**Plusy:** balkon, garaż\n\n**Uwaga:** parter");
});

it('pokazuje koszt miesięczny i oceny cząstkowe', function () {
    $analysis = ListingAnalysis::factory()->create([
        'score' => 7,
        'monthly_cost' => 3150,
        'cost_breakdown' => ['rent' => 2500, 'admin_fee' => 450, 'other_fees' => 200, 'note' => 'Media ok. 200 zł, kaucja 5000 zł.'],
        'sub_scores' => ['cost' => 6, 'location' => 9, 'standard' => 7, 'fit' => 8],
    ]);

    $fields = collect(app(DiscordMessages::class)->listing($analysis->listing, $analysis->search, $analysis)['embeds'][0]['fields'])
        ->pluck('value', 'name');

    expect($fields['💸 Koszt miesięczny'])->toBe("**3 150 zł** / mies.\nnajem 2 500 zł + czynsz 450 zł + opłaty 200 zł\n_Media ok. 200 zł, kaucja 5000 zł._")
        ->and($fields['📊 Ocena 7/10'])->toBe('Koszt **6** · Lokalizacja **9** · Standard **7** · Dopasowanie **8**');
});

it('czynsz bierze z oceny AI, gdy portal go nie podał', function (?int $aiFee, string $expected) {
    $analysis = ListingAnalysis::factory()->create(['cost_breakdown' => ['rent' => 2900, 'admin_fee' => $aiFee, 'other_fees' => null, 'note' => 'x']]);
    $analysis->listing->update(['price' => 2900, 'rent_extra' => null]);

    $fields = collect(app(DiscordMessages::class)->listing($analysis->listing->fresh(), $analysis->search, $analysis)['embeds'][0]['fields'])
        ->pluck('value', 'name');

    expect($fields['💰 Najem'])->toBe($expected);
})->with([
    'czynsz z opisu' => [570, '2 900 zł + 570 zł czynsz'],
    'czynsz nieznany' => [null, '2 900 zł + czynsz nieznany'],
]);

it('przy sprzedaży pole nazywa się Cena', function () {
    $listing = Listing::factory()->create(['deal_type' => 'sale', 'price' => 650000, 'rent_extra' => null]);

    $fields = collect(app(DiscordMessages::class)->listing($listing, Search::factory()->create(['deal_type' => 'sale']))['embeds'][0]['fields'])
        ->pluck('value', 'name');

    expect($fields['💰 Cena'])->toBe('650 000 zł');
});

it('bez oceny przy włączonym AI dopisuje brak oceny', function () {
    config(['ai.providers.opencode.key' => 'test-key']);
    $listing = Listing::factory()->create(['description' => 'Opis.']);

    $embed = app(DiscordMessages::class)->listing($listing, Search::factory()->create())['embeds'][0];

    expect($embed['description'])->toContain('Brak oceny AI');
});

it('buduje podsumowanie', function () {
    $search = Search::factory()->create();
    $listings = Listing::factory()->count(3)->create(['district' => 'Zalesie']);

    $embed = app(DiscordMessages::class)->digest($search, $listings->take(2), 3, true)['embeds'][0];

    expect($embed['title'])->toBe('Pierwszy przebieg: 3 oferty pasuje do filtrów')
        ->and($embed['description'])->toContain($listings[0]->url)
        ->and($embed['description'])->toContain('Zalesie')
        ->and($embed['description'])->toContain('Pokazuję 2 najlepszych z 3');
});

it('wybiera webhook dla typu transakcji', function () {
    config(['notify.discord.webhook_url_rent' => 'https://discord.com/api/webhooks/2/rent']);
    $webhook = app(DiscordWebhook::class);

    expect($webhook->urlFor(DealType::Rent))->toBe('https://discord.com/api/webhooks/2/rent')
        ->and($webhook->urlFor(DealType::Sale))->toBe(WEBHOOK)
        ->and($webhook->urlFor())->toBe(WEBHOOK);
});

it('ponawia po 429 z czasem z odpowiedzi', function () {
    Http::fake([WEBHOOK => Http::sequence()->push(['retry_after' => 2.5], 429)->push('', 204)]);

    app(DiscordWebhook::class)->send(['content' => 'x']);

    Http::assertSentCount(2);
    Http::assertSent(fn ($request) => $request['username'] === 'Mieszkania');
    Sleep::assertSlept(fn ($duration) => (int) $duration->totalMilliseconds === 2600);
});

it('błąd nie pokazuje adresu webhooka', function () {
    Http::fake([WEBHOOK => Http::response(['message' => 'Unknown Webhook'], 404)]);

    expect(fn () => app(DiscordWebhook::class)->send(['content' => 'x']))
        ->toThrow(RuntimeException::class, 'Discord odpowiedział 404');

    try {
        app(DiscordWebhook::class)->send(['content' => 'x']);
    } catch (RuntimeException $e) {
        expect($e->getMessage())->not->toContain('abc');
    }
});

it('robi przerwę między wiadomościami', function () {
    Http::fake([WEBHOOK => Http::response('', 204)]);

    app(DiscordWebhook::class)->send(['content' => '1']);
    app(DiscordWebhook::class)->send(['content' => '2']);

    Sleep::assertSlept(fn ($duration) => $duration->totalMilliseconds > 1000 && $duration->totalMilliseconds <= 1500);
});

it('notify:test wysyła wiadomość', function () {
    Http::fake([WEBHOOK => Http::response('', 204)]);

    $this->artisan('notify:test')->expectsOutputToContain('Wysłano')->assertSuccessful();

    Http::assertSent(fn ($request) => $request['embeds'][0]['title'] === 'Webhook działa');
});

it('notify:test bez webhooka zgłasza błąd', function () {
    config(['notify.discord.webhook_url' => null]);

    $this->artisan('notify:test')->expectsOutputToContain('DISCORD_WEBHOOK_URL')->assertFailed();
});
