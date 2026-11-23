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
    'najem w kategorii sprzedaży' => [[], ['price' => 2800], false],
    'rynek pierwotny odrzucony' => [['market' => 'secondary'], ['raw' => ['market' => 'primary']], false],
    'rynek wtórny z OLX' => [['market' => 'secondary'], ['portal' => 'olx', 'raw' => ['params' => [['key' => 'market', 'value' => ['key' => 'secondary']]]]], true],
    'Otodom w inwestycji dewelopera' => [['market' => 'secondary'], ['raw' => ['developmentId' => 123]], false],
    'Morizon ze strony ogłoszenia' => [['market' => 'secondary'], ['portal' => 'morizon', 'raw' => ['details' => ['market' => 'pierwotny']]], false],
    'bez filtra rynku' => [[], ['raw' => ['market' => 'primary']], true],
    'OLX bez mebli, ale wykończone' => [['move_in_ready' => true], ['portal' => 'olx', 'description' => 'Gotowe do zamieszkania.', 'raw' => ['params' => [['key' => 'furniture', 'value' => ['key' => 'no']]]]], true],
    'nieumeblowane, do wprowadzenia' => [['move_in_ready' => true], ['description' => 'Mieszkanie nieumeblowane, do wprowadzenia.'], true],
    'stan deweloperski' => [['move_in_ready' => true], ['description' => 'Lokal w stanie deweloperskim.'], false],
    'do remontu' => [['move_in_ready' => true], ['description' => 'Mieszkanie do remontu, okazja.'], false],
    'Otodom do wykończenia ze strony ogłoszenia' => [['move_in_ready' => true], ['raw' => ['details' => ['condition' => 'to_completion']]], false],
    'Rzeszowiak do zamieszkania' => [['move_in_ready' => true], ['portal' => 'rzeszowiak', 'raw' => ['details' => ['condition' => 'do zamieszkania']]], true],
    'brak informacji o stanie przechodzi' => [['move_in_ready' => true], ['description' => 'Trzy pokoje, balkon.'], true],
    'do remontu, ale bez filtra' => [[], ['description' => 'Mieszkanie do remontu.'], true],
    'wizualizacja w opisie' => [['exclude_visualizations' => true], ['description' => 'Zdjęcia to wizualizacje wnętrz.'], false],
    'zdjęcia poglądowe' => [['exclude_visualizations' => true], ['description' => 'Zdjęcia poglądowe.'], false],
    'wizualizacja bez filtra' => [[], ['description' => 'Wizualizacja salonu.'], true],
]);

it('rozpoznaje, czy da się od razu zamieszkać', function (string $description, array $raw, ?bool $expected) {
    expect(ListingFeatures::of(listingData(['description' => $description, 'raw' => $raw]))->ready)->toBe($expected);
})->with([
    ['Mieszkanie gotowe do zamieszkania.', [], true],
    ['Mieszkanie po remoncie, bez mebli.', [], true],
    ['Nie wymaga remontu.', [], true],
    ['Umeblowane, z AGD.', [], true],
    ['Łazienka do remontu.', [], false],
    ['Mieszkanie wymaga generalnego remontu.', [], false],
    ['Do wykończenia według własnego gustu.', [], false],
    ['Opis bez stanu.', ['details' => ['condition' => 'ready_to_use']], true],
    ['Gotowe do zamieszkania.', ['details' => ['condition' => 'to_renovation']], false],
    ['Trzy pokoje, balkon.', [], null],
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
