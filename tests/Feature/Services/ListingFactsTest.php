<?php

use App\Models\Listing;
use App\Services\ListingFacts;

function factsFor(string $description, array $details = []): array
{
    return ListingFacts::of(Listing::factory()->make(['title' => 'Mieszkanie', 'description' => $description, 'raw' => ['details' => $details]]));
}

it('rozpoznaje ogrzewanie z portalu i z opisu', function (string $description, array $details, ?string $expected) {
    expect(factsFor($description, $details)['heating'])->toBe($expected);
})->with([
    ['', ['heating' => 'urban'], 'miejskie'],
    ['', ['heating' => 'Gazowe'], 'gazowe'],
    ['Ogrzewanie z sieci miejskiej (MPEC).', [], 'miejskie'],
    ['Piec gazowy dwufunkcyjny, ogrzewanie gazowe.', [], 'gazowe'],
    ['Dom ogrzewany pompą ciepła.', [], 'pompa ciepła'],
    ['Trzy pokoje, balkon.', [], null],
]);

it('rozpoznaje rok budowy', function (string $description, array $details, ?int $expected) {
    expect(factsFor($description, $details)['build_year'])->toBe($expected);
})->with([
    ['', ['build_year' => '2018'], 2018],
    ['Blok z 2006 roku, bez windy.', [], 2006],
    ['Rok budowy: 1985.', [], 1985],
    ['Mieszkanie 2 pokoje, cena 2000 zł.', [], null],
]);

it('rozpoznaje garaż i komórkę oraz czy są w cenie', function (string $description, ?array $parking, ?array $storage) {
    $facts = factsFor($description);

    expect($facts['parking'])->toBe($parking)->and($facts['storage'])->toBe($storage);
})->with([
    'garaż w cenie' => ['Do mieszkania przynależy garaż podziemny w cenie. Piwnica.', ['type' => 'garaż podziemny', 'price' => 'included'], ['type' => 'piwnica', 'price' => null]],
    'miejsce za dopłatą' => ['Możliwość zakupu miejsca postojowego w garażu podziemnym za 38 000 zł. Komórka lokatorska w cenie.', ['type' => 'garaż podziemny', 'price' => 'extra'], ['type' => 'komórka lokatorska', 'price' => 'included']],
    'komórka za dopłatą' => ['Komórka lokatorska - 12 000 zł. Parking ogólnodostępny.', ['type' => 'parking ogólnodostępny', 'price' => null], ['type' => 'komórka lokatorska', 'price' => 'extra']],
    'nic' => ['Trzy pokoje, balkon.', null, null],
]);

it('etykiety mają kolor według ceny dodatku', function () {
    $badges = ListingFacts::badges(Listing::factory()->make(['description' => 'Garaż w cenie. Komórka lokatorska za dopłatą. Blok z 2010 roku, winda.', 'raw' => ['details' => ['heating' => 'urban']]]));

    expect($badges)->toMatchArray([
        'Garaż w cenie' => 'success',
        'Komórka lokatorska za dopłatą' => 'warning',
        'Ogrzewanie miejskie' => 'gray',
        'Budynek z 2010' => 'gray',
        'Winda' => 'gray',
    ]);
});
