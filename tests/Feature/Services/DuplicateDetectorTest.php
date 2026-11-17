<?php

use App\Enums\DealType;
use App\Models\Listing;
use App\Scrapers\Data\ListingData;
use App\Services\ListingUpserter;

function portalListing(array $overrides = []): ListingData
{
    return new ListingData(...array_merge([
        'portal' => 'olx',
        'externalId' => (string) random_int(1, PHP_INT_MAX),
        'url' => 'https://www.olx.pl/d/oferta/x.html',
        'title' => 'Mieszkanie',
        'dealType' => DealType::Rent,
        'price' => 3000,
        'area' => 50.0,
        'rooms' => 3,
        'city' => 'Rzeszów',
    ], $overrides));
}

it('oznacza kopię z OLX po adresie oferty Otodom', function () {
    $upserter = app(ListingUpserter::class);
    $otodom = $upserter->upsert(portalListing([
        'portal' => 'otodom',
        'url' => 'https://www.otodom.pl/pl/oferta/mieszkanie-ID4Bvqj',
        'price' => 2000,
    ]))->listing;

    // Cena i metraż inne, ale adres wskazuje tę samą ofertę.
    $olx = $upserter->upsert(portalListing([
        'price' => 2500,
        'area' => 70.0,
        'raw' => ['external_url' => 'https://www.otodom.pl/pl/oferta/mieszkanie-ID4Bvqj?utm=olx'],
    ]))->listing;

    expect($olx->fresh()->duplicate_of_id)->toBe($otodom->id)
        ->and($otodom->fresh()->duplicate_of_id)->toBeNull();
});

it('oznacza ofertę Otodom, gdy kopia z OLX była pierwsza', function () {
    $upserter = app(ListingUpserter::class);
    $olx = $upserter->upsert(portalListing([
        'price' => 2500,
        'raw' => ['external_url' => 'https://www.otodom.pl/pl/oferta/mieszkanie-ID4Bvqj'],
    ]))->listing;

    $otodom = $upserter->upsert(portalListing([
        'portal' => 'otodom',
        'url' => 'https://www.otodom.pl/pl/oferta/mieszkanie-ID4Bvqj',
        'price' => 9999,
    ]))->listing;

    expect($otodom->fresh()->duplicate_of_id)->toBe($olx->id);
});

it('rozpoznaje duplikat po cenie i metrażu', function (array $second, bool $duplicate) {
    $upserter = app(ListingUpserter::class);
    $first = $upserter->upsert(portalListing(['portal' => 'otodom']))->listing;

    $listing = $upserter->upsert(portalListing($second))->listing->fresh();

    expect($listing->duplicate_of_id)->toBe($duplicate ? $first->id : null);
})->with([
    'cena +1,6%, metraż +0,5 m2' => [['price' => 3050, 'area' => 50.5], true],
    'cena -1,9%' => [['price' => 2943], true],
    'cena -2% dokładnie' => [['price' => 2940], false],
    'cena +2,02%' => [['price' => 3062], false],
    'cena +3%' => [['price' => 3090], false],
    'metraż +1 m2' => [['area' => 51.0], false],
    'inne miasto' => [['city' => 'Kraków'], false],
    'inny typ transakcji' => [['dealType' => DealType::Sale], false],
    'ten sam portal' => [['portal' => 'otodom'], false],
    'brak ceny' => [['price' => null], false],
]);

it('pomija oferty niewidziane od ponad 7 dni', function () {
    $upserter = app(ListingUpserter::class);
    $upserter->upsert(portalListing(['portal' => 'otodom']));

    $this->travel(8)->days();
    $listing = $upserter->upsert(portalListing())->listing;

    expect($listing->fresh()->duplicate_of_id)->toBeNull();
});

it('łączy z oryginałem, a nie z innym duplikatem', function () {
    $upserter = app(ListingUpserter::class);
    $original = $upserter->upsert(portalListing(['portal' => 'otodom']))->listing;
    $upserter->upsert(portalListing());
    $third = $upserter->upsert(portalListing(['portal' => 'morizon']))->listing;

    expect($third->fresh()->duplicate_of_id)->toBe($original->id)
        ->and(Listing::whereNotNull('duplicate_of_id')->count())->toBe(2);
});
