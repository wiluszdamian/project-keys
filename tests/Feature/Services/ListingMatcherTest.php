<?php

use App\Enums\DealType;
use App\Models\Search;
use App\Scrapers\Data\ListingData;
use App\Services\ListingMatcher;

function listingData(array $overrides = []): ListingData
{
    return new ListingData(...array_merge([
        'portal' => 'otodom',
        'externalId' => '1',
        'url' => 'https://example.test/1',
        'title' => 'Mieszkanie',
        'dealType' => DealType::Rent,
        'price' => 3000,
        'area' => 50.0,
        'rooms' => 2,
        'city' => 'Kraków',
        'district' => 'Prądnik Biały',
    ], $overrides));
}

it('sprawdza filtry wyszukiwania', function (array $search, array $listing, bool $expected) {
    $search = new Search(array_merge(['deal_type' => 'rent', 'city' => 'Kraków'], $search));

    expect(app(ListingMatcher::class)->matches($search, listingData($listing)))->toBe($expected);
})->with([
    'bez filtrów' => [[], [], true],
    'inny typ transakcji' => [[], ['dealType' => DealType::Sale], false],
    'cena w zakresie' => [['price_min' => 2000, 'price_max' => 3000], [], true],
    'cena za wysoka' => [['price_max' => 2999], [], false],
    'brak ceny nie odrzuca' => [['price_max' => 2000], ['price' => null], true],
    'metraż za mały' => [['area_min' => 51], [], false],
    'za mało pokoi' => [['rooms_min' => 3], [], false],
    'za dużo pokoi' => [['rooms_max' => 1], [], false],
    'dzielnica bez polskich znaków i wielkości liter' => [['districts' => ['pradnik bialy', 'Krowodrza']], [], true],
    'inna dzielnica' => [['districts' => ['Krowodrza']], [], false],
    'brak dzielnicy w ogłoszeniu' => [['districts' => ['Krowodrza']], ['district' => null], false],
]);
