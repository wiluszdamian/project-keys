<?php

use App\Models\Listing;
use App\Services\LeaseTerms;

function leaseOf(string $description, array $attributes = []): array
{
    return LeaseTerms::of(Listing::factory()->make(['description' => $description, 'is_private_owner' => null] + $attributes));
}

it('rozpoznaje rodzaj umowy z opisu', function (string $description, ?string $contract) {
    expect(leaseOf($description)['contract'])->toBe($contract);
})->with([
    ['Umowa w formie najmu okazjonalnego.', 'najem okazjonalny'],
    ['Forma umowy: tylko najem okazjonalny.', 'najem okazjonalny'],
    ['Wynajem na doby, idealne dla turystów.', 'krótkoterminowy (na doby)'],
    ['Zwykła umowa najmu.', null],
]);

it('czyta minimalny okres najmu', function (string $description, ?string $period) {
    expect(leaseOf($description)['min_period'])->toBe($period);
})->with([
    ['Najem na okres 12 miesięcy, w formie najmu okazjonalnego.', '12 mies.'],
    ['Umowa na minimum rok.', 'rok'],
    ['Minimalny okres najmu to 6 miesięcy.', '6 mies.'],
    ['Szukam najemcy długoterminowego.', 'długoterminowo'],
    ['Mieszkanie 2 pokoje, 3 piętro.', null],
]);

it('czyta kaucję z kwotą i bez kwoty', function (string $description, ?int $deposit, ?string $note) {
    $terms = leaseOf($description);

    expect($terms['deposit'])->toBe($deposit)
        ->and($terms['deposit_note'])->toBe($note);
})->with([
    ['Wymagana kaucja zwrotna w wysokości 3 000 zł.', 3000, null],
    ['Kaucja: 2500zł. Zapraszam.', 2500, null],
    ['Czynsz 750 zł, kaucja 2.300 zł, prowizja biura.', 2300, null],
    ['Prąd według zużycia. 2500 zł – kaucja zwrotna.', 2500, null],
    ['Cena 2400 zł kaucja do ustalenia.', null, 'do ustalenia'],
    ['Kaucja w wysokości dwumiesięcznego czynszu.', null, 'równowartość 2 mies. najmu'],
    ['Wymagana kaucja zwrotna.', null, 'jest, kwota nie podana'],
    ['Bez wzmianki.', null, null],
]);

it('bierze kaucję i termin z danych portalu przed opisem', function () {
    $terms = leaseOf('Kaucja 1000 zł.', ['raw' => ['details' => ['deposit' => '4000', 'free_from' => '2026-12-01']]]);

    expect($terms['deposit'])->toBe(4000)->and($terms['free_from'])->toBe('2026-12-01');
});

it('rozpoznaje zwierzęta', function (string $description, ?bool $pets) {
    expect(leaseOf($description)['pets'])->toBe($pets);
})->with([
    ['Wynajem bez zwierząt. Pośrednikom dziękuję.', false],
    ['Zwierzęta nie są akceptowalne.', false],
    ['Dla osób pracujących, nie posiadających zwierząt.', false],
    ['Bez dużych zwierząt domowych.', false],
    ['Zgoda na zwierzaki, szczegóły do ustalenia.', true],
    ['Akceptuję zwierzęta domowe.', true],
    ['Zwierzęta akceptowalne przy zwiększonej kaucji.', true],
    ['Blisko park i sklepy.', null],
]);

it('bierze zwierzęta z parametru OLX', function () {
    $terms = leaseOf('Zwierzęta mile widziane.', ['portal' => 'olx', 'raw' => ['params' => [['key' => 'pets', 'value' => ['key' => 'Nie', 'label' => 'Nie']]]]]);

    expect($terms['pets'])->toBeFalse();
});

it('rozpoznaje prowizję', function (string $description, ?bool $commission) {
    expect(leaseOf($description)['commission'])->toBe($commission);
})->with([
    ['Wynajem bez prowizji! Bezpośrednio od właściciela.', false],
    ['Brak prowizji dla biura.', false],
    ['Kaucja 3000 zł, prowizja biura.', true],
    ['W przypadku realizacji transakcji pobieramy wynagrodzenie.', true],
    ['Zapraszam.', null],
]);

it('czyta termin dostępności z opisu', function (string $description, ?string $from) {
    expect(leaseOf($description)['free_from'])->toBe($from);
})->with([
    ['Mieszkanie dostępne od zaraz.', 'od zaraz'],
    ['Dostępne od 1 grudnia!', '1 grudnia'],
    ['Wolne od 01.12.2026.', '01.12.2026'],
    ['Mieszkanie dostępne bezpośrednio od właściciela.', null],
]);

it('pokazuje ogłoszeniodawcę z nazwą biura', function () {
    $otodom = Listing::factory()->make(['is_private_owner' => false, 'raw' => ['agency' => ['name' => 'HOME', 'type' => 'AGENCY']]]);
    $morizon = Listing::factory()->make(['portal' => 'morizon', 'is_private_owner' => false, 'raw' => ['seller' => 'Dream House Brokers']]);
    $private = Listing::factory()->make(['is_private_owner' => true]);

    expect(LeaseTerms::advertiser($otodom))->toBe('biuro: HOME')
        ->and(LeaseTerms::advertiser($morizon))->toBe('biuro: Dream House Brokers')
        ->and(LeaseTerms::advertiser($private))->toBe('osoba prywatna');
});

it('daje pełną listę warunków najmu z "nie podano" i krótki opis do listy', function () {
    $listing = Listing::factory()->make(['is_private_owner' => true, 'description' => 'Najem okazjonalny na minimum 12 miesięcy. Bez zwierząt.']);

    expect(LeaseTerms::labels($listing, withUnknown: true))->toMatchArray([
        'Rodzaj umowy' => 'najem okazjonalny',
        'Okres najmu' => '12 mies.',
        'Kaucja' => 'nie podano',
        'Zwierzęta' => 'nie',
        'Ogłoszeniodawca' => 'osoba prywatna',
        'Prowizja' => 'raczej nie (osoba prywatna)',
    ])->and(LeaseTerms::short($listing))->toBe('prywatnie · okazjonalny · min. 12 mies. · bez zwierząt');
});

it('przy sprzedaży pokazuje tylko ogłoszeniodawcę', function () {
    $listing = Listing::factory()->make(['deal_type' => 'sale', 'is_private_owner' => false]);

    expect(LeaseTerms::labels($listing))->toBe(['Ogłoszeniodawca' => 'biuro / firma'])
        ->and(LeaseTerms::short($listing))->toBeNull();
});
