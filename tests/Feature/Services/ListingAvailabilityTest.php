<?php

use App\Enums\ListingStatus;
use App\Models\Listing;
use App\Models\Search;
use App\Models\User;
use App\Scrapers\Data\ListingData;
use App\Services\ListingAvailability;
use App\Services\ListingUpserter;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Sleep;

beforeEach(function () {
    Sleep::fake();
});

function page(int $status = 200, string $body = '<html></html>', ?string $url = null): array
{
    return ['status' => $status, 'body' => $body, 'url' => $url ?? 'https://example.test/x'];
}

it('rozpoznaje zdjęte ogłoszenie po kodzie 404 i 410', function (int $status) {
    $listing = Listing::factory()->make(['portal' => 'olx', 'url' => 'https://www.olx.pl/d/oferta/a-CID3-ID1.html']);

    expect(ListingAvailability::classify($listing, page($status, url: $listing->url)))->toContain((string) $status);
})->with([404, 410]);

it('rozpoznaje komunikat portalu na stronie zdjętego ogłoszenia', function (string $portal, string $body) {
    $listing = Listing::factory()->make(['portal' => $portal, 'url' => 'https://example.test/o/oferta-1']);

    expect(ListingAvailability::classify($listing, page(body: $body, url: $listing->url)))->toBe('portal oznacza ogłoszenie jako archiwalne');
})->with([
    'otodom' => ['otodom', '<title>Archiwalne: 3 pokoje, mieszkanie na wynajem</title>'],
    'domiporta' => ['domiporta', '<title>Wynajmę mieszkanie - Archiwum Domiporta.pl</title>'],
    'morizon' => ['morizon', '<div>To ogłoszenie nie jest już dostępne. Na szczęście mamy coś podobnego.</div>'],
    'nieruchomosci_online' => ['nieruchomosci_online', '<div>Ogłoszenie archiwalne</div>'],
    'adresowo' => ['adresowo', '<span>Ogłoszenie usunięte</span>'],
    // Rzeszowiak podaje ISO-8859-2.
    'rzeszowiak' => ['rzeszowiak', mb_convert_encoding('<b>Ogłoszenie wygasło</b>', 'ISO-8859-2', 'UTF-8')],
]);

it('nie oznacza aktywnej oferty', function (string $portal, string $body) {
    $listing = Listing::factory()->make(['portal' => $portal, 'url' => 'https://example.test/o/oferta-1']);

    expect(ListingAvailability::classify($listing, page(body: $body, url: $listing->url)))->toBeNull();
})->with([
    // Opis z "nieaktualne" to nie komunikat portalu.
    'rzeszowiak' => ['rzeszowiak', 'Zdjęcia częściowo nieaktualne.'],
    // OLX ma teksty o niedostępnym ogłoszeniu w kodzie każdej strony: liczy się tylko kod 410.
    'olx' => ['olx', '"To ogłoszenie nie jest już dostępne"'],
    'otodom' => ['otodom', '"status":"active"'],
]);

it('przekierowanie na stronę główną albo listę ofert to zdjęta oferta, zmiana sluga nie', function (string $from, string $to, bool $gone) {
    $listing = Listing::factory()->make(['portal' => 'pierwsze_pietro', 'url' => $from]);

    expect(ListingAvailability::classify($listing, page(url: $to)) !== null)->toBe($gone);
})->with([
    ['https://1pietro.net/mieszkania/wynajem/3-pokoje,nieruchomosc4495/', 'https://1pietro.net/', true],
    ['https://www.domiporta.pl/nieruchomosci/wynajme-mieszkanie/156924433', 'https://www.domiporta.pl/nieruchomosci/sprzedam', true],
    ['https://www.otodom.pl/pl/oferta/stary-slug-ID4Db2i', 'https://www.otodom.pl/pl/oferta/nowy-slug-ID4Db2i', false],
    ['https://stoprocent-nieruchomosci.pl/nieruchomosci/3-pokoje-m%c2%b2', 'https://stoprocent-nieruchomosci.pl/nieruchomosci/3-pokoje-m%C2%B2', false],
]);

it('sprawdza stare oferty, oznacza zdjęte i pomija widziane niedawno', function () {
    Http::fake([
        'example.test/gone' => Http::response('gone', 410),
        'example.test/live' => Http::response('<html>ok</html>'),
        '*' => Http::response('nie powinno tu dojść', 500),
    ]);
    $search = Search::factory()->create();
    $gone = Listing::factory()->create(['url' => 'https://example.test/gone', 'last_seen_at' => now()->subDay(), 'deal_at' => now(), 'deal_reasons' => [['type' => 'x', 'text' => 'y']]]);
    $live = Listing::factory()->create(['url' => 'https://example.test/live', 'last_seen_at' => now()->subDay()]);
    $fresh = Listing::factory()->create(['url' => 'https://example.test/fresh', 'last_seen_at' => now()->subHour()]);
    $search->listings()->attach([$gone->id, $live->id, $fresh->id], ['matched_at' => now()]);

    $this->artisan('listings:check-active')->assertSuccessful();

    expect($gone->fresh())
        ->inactive_at->not->toBeNull()
        ->inactive_reason->toContain('410')
        ->deal_at->toBeNull();
    expect($live->fresh())->inactive_at->toBeNull()->availability_checked_at->not->toBeNull();
    expect($fresh->fresh()->availability_checked_at)->toBeNull();

    // Drugi przebieg: nic do sprawdzenia (aktywna sprawdzona przed chwilą, zdjęta już oznaczona).
    Http::fake(['*' => Http::response('', 500)]);
    $this->artisan('listings:check-active')->expectsOutputToContain('Sprawdzone: 0')->assertSuccessful();
});

it('oferta znów w wynikach portalu wraca do aktywnych', function () {
    $listing = Listing::factory()->create(['portal' => 'otodom', 'external_id' => 'ID1', 'inactive_at' => now(), 'inactive_reason' => 'x']);

    app(ListingUpserter::class)->upsert(new ListingData(
        portal: 'otodom', externalId: 'ID1', url: $listing->url, title: $listing->title, dealType: $listing->deal_type, price: $listing->price,
    ));

    expect($listing->fresh())->inactive_at->toBeNull()->inactive_reason->toBeNull();
});

it('mieszkanie jest zdjęte dopiero, gdy nie ma go na żadnym portalu', function () {
    $original = Listing::factory()->create(['inactive_at' => now()]);
    $copy = Listing::factory()->create(['portal' => 'olx', 'duplicate_of_id' => $original->id]);

    expect($original->fresh()->isGone())->toBeFalse()
        ->and(Listing::available()->whereKey($original->id)->exists())->toBeTrue();

    $copy->update(['inactive_at' => now()]);

    expect($original->fresh()->isGone())->toBeTrue()
        ->and(Listing::gone()->whereKey($original->id)->exists())->toBeTrue();
});

it('powiadamia w panelu, gdy zniknie ulubiona oferta', function () {
    $user = User::factory()->create();
    $favorite = Listing::factory()->create(['status' => ListingStatus::Favorite]);
    $other = Listing::factory()->create();

    app(ListingAvailability::class)->markInactive($favorite, 'ogłoszenie usunięte z portalu (HTTP 410)');
    app(ListingAvailability::class)->markInactive($other, 'ogłoszenie usunięte z portalu (HTTP 410)');

    expect($user->notifications()->count())->toBe(1)
        ->and($user->notifications()->first()->data['title'])->toContain('Oferta zdjęta');
});
