<?php

use App\Enums\DealType;
use App\Models\Search;
use App\Scrapers\Data\ListingData;
use App\Services\ListingFeatures;
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

it('sprawdza rynek, meble i wizualizacje', function (array $search, array $listing, bool $expected) {
    $search = new Search(array_merge(['deal_type' => 'sale', 'city' => 'Kraków'], $search));

    expect(app(ListingMatcher::class)->matches($search, listingData(array_merge(['dealType' => DealType::Sale, 'price' => 500000], $listing))))->toBe($expected);
})->with([
    'rynek nieznany przechodzi' => [['market' => 'secondary'], [], true],
    'rynek pierwotny odrzucony' => [['market' => 'secondary'], ['raw' => ['market' => 'primary']], false],
    'rynek wtórny z OLX' => [['market' => 'secondary'], ['portal' => 'olx', 'raw' => ['params' => [['key' => 'market', 'value' => ['key' => 'secondary']]]]], true],
    'Otodom w inwestycji dewelopera' => [['market' => 'secondary'], ['raw' => ['developmentId' => 123]], false],
    'Morizon ze strony ogłoszenia' => [['market' => 'secondary'], ['portal' => 'morizon', 'raw' => ['details' => ['market' => 'pierwotny']]], false],
    'bez filtra rynku' => [[], ['raw' => ['market' => 'primary']], true],
    'OLX bez mebli' => [['furnished_only' => true], ['portal' => 'olx', 'raw' => ['params' => [['key' => 'furniture', 'value' => ['key' => 'no']]]]], false],
    'OLX umeblowane' => [['furnished_only' => true], ['portal' => 'olx', 'raw' => ['params' => [['key' => 'furniture', 'value' => ['key' => 'yes']]]]], true],
    'nieumeblowane w opisie' => [['furnished_only' => true], ['description' => 'Mieszkanie nieumeblowane, do wprowadzenia.'], false],
    'stan deweloperski' => [['furnished_only' => true], ['description' => 'Lokal w stanie deweloperskim.'], false],
    'umeblowane w opisie' => [['furnished_only' => true], ['description' => 'Sprzedaję mieszkanie umeblowane, meble zostają.'], true],
    'brak informacji o meblach przechodzi' => [['furnished_only' => true], ['description' => 'Trzy pokoje, balkon.'], true],
    'bez mebli, ale bez filtra' => [[], ['description' => 'Mieszkanie bez mebli.'], true],
    'wizualizacja w opisie' => [['exclude_visualizations' => true], ['description' => 'Zdjęcia to wizualizacje wnętrz.'], false],
    'zdjęcia poglądowe' => [['exclude_visualizations' => true], ['description' => 'Zdjęcia poglądowe.'], false],
    'wizualizacja bez filtra' => [[], ['description' => 'Wizualizacja salonu.'], true],
]);

it('rozpoznaje meble w opisie', function (string $description, ?bool $expected) {
    expect(ListingFeatures::of(listingData(['description' => $description]))->furnished)->toBe($expected);
})->with([
    ['Mieszkanie umeblowane i wyposażone.', true],
    ['Mieszkanie jest umeblowane.', true],
    ['Mieszkanie nie jest umeblowane.', false],
    ['Lokal nieumeblowany.', false],
    ['Sprzedaję wraz z meblami.', true],
    ['Kuchnia z zabudową meblową i AGD.', true],
    ['Trzy pokoje, balkon, piwnica.', null],
]);
