<?php

use App\Enums\DealType;
use App\Models\Listing;
use App\Models\Search;
use App\Scrapers\GratkaScraper;
use App\Scrapers\MorizonScraper;
use App\Services\DuplicateDetector;

function networkFixture(string $portal, string $name): string
{
    return file_get_contents(base_path("tests/Fixtures/{$portal}/{$name}.html"));
}

it('buduje adresy Morizon od najnowszych i Gratki', function () {
    $rent = Search::factory()->make(['deal_type' => 'rent', 'city' => 'Rzeszów']);
    $sale = Search::factory()->make(['deal_type' => 'sale', 'city' => 'Rudna Mała']);

    expect(app(MorizonScraper::class)->buildSearchUrl($rent))->toBe('https://www.morizon.pl/do-wynajecia/mieszkania/najnowsze/rzeszow/')
        ->and(app(MorizonScraper::class)->buildSearchUrl($sale, 2))->toBe('https://www.morizon.pl/mieszkania/najnowsze/rzeszowski/gmina-glogow-malopolski/rudna-mala/?page=2')
        ->and(app(GratkaScraper::class)->buildSearchUrl($rent, 3))->toBe('https://gratka.pl/nieruchomosci/mieszkania/rzeszow/wynajem?page=3')
        ->and(app(GratkaScraper::class)->buildSearchUrl($sale))->toBe('https://gratka.pl/nieruchomosci/mieszkania/rudna-mala-37382');
});

it('odczytuje Morizon: wynajem w grupach i sprzedaż jako lista', function () {
    $rent = app(MorizonScraper::class)->parse(networkFixture('morizon', 'search_rent'), Search::factory()->make(['deal_type' => 'rent']));

    expect($rent['total_pages'])->toBe(7)
        ->and($rent['listings'])->toHaveCount(35);

    [$first, $second] = $rent['listings'];

    expect($first)
        ->externalId->toBe('1543354701')
        ->url->toBe('https://www.morizon.pl/oferta/wynajem-mieszkanie-rzeszow-srodmiescie-mjr-waclawa-kopisto-75m2-mzn2048405206')
        ->title->toBe('Dwupoziomowe mieszkanie w centrum, aleja Kopisto')
        ->dealType->toBe(DealType::Rent)
        ->price->toBe(3000)
        ->area->toBe(75.0)
        ->rooms->toBe(3)
        ->floor->toBe('3')
        ->city->toBe('Rzeszów')
        ->district->toBe('Śródmieście')
        ->street->toBeNull()
        ->isPrivateOwner->toBeFalse()
        ->imageUrl->toBeNull();

    expect($second)
        ->externalId->toBe('1543354408')
        ->street->toBe('Grunwaldzka')
        ->district->toBeNull()
        ->floor->toBe('6')
        ->imageUrl->toStartWith('https://img1.staticmorizon.com.pl/thumb/aHR0cHM6Ly9kLWdyLmNkbmdyLnBsL2thZHJ5L2svci9nci1vZ2wvZjEvNTYvNDk3MTU5NzlfMTY2MTMwMDA0OV9taWVzemthbmllLXJ6ZXN6b3ctdWwtZ3J1bndhbGR6a2EuanBn/3x2_xs:fill_and_crop/');

    $sale = app(MorizonScraper::class)->parse(networkFixture('morizon', 'search_sale'), Search::factory()->make(['deal_type' => 'sale']));

    expect($sale['total_pages'])->toBe(45)
        ->and($sale['listings'][0])->externalId->toBe('1543354720')->price->toBe(484000)->area->toBe(57.0)->floor->toBe('parter')->street->toBe('Aleja Generała Władysława Sikorskiego')
        ->and($sale['listings'][1])->district->toBe('Budziwój')->price->toBe(585700);
});

it('odczytuje Gratkę z tymi samymi id co Morizon', function () {
    $rent = app(GratkaScraper::class)->parse(networkFixture('gratka', 'search_rent'), Search::factory()->make(['deal_type' => 'rent']));

    expect($rent['listings'][0])
        ->externalId->toBe('1543354701')
        ->url->toBe('https://gratka.pl/nieruchomosci/mieszkanie-rzeszow-srodmiescie-mjr-waclawa-kopisto/ob/49716769')
        ->and($rent['listings'][1]->imageUrl)->toStartWith('https://thumbs.cdngr.pl/thumb/')
        ->and($rent['listings'][1]->raw['network_id'])->toBe('1543354408');
});

it('odczytuje stronę oferty Morizon i Gratki', function () {
    $morizon = app(MorizonScraper::class)->parseOffer(networkFixture('morizon', 'offer'));

    expect($morizon)
        ->area->toBe(48.51)
        ->floor->toBe('6')
        ->is_private_owner->toBeFalse()
        ->and($morizon['description'])->toStartWith('Biuro nieruchomości Pierwsze Piętro')
        ->and($morizon['description'])->toContain('czynsz w wysokości ok. 800 PLN')
        ->and($morizon['details'])->toMatchArray(['build_year' => '1985', 'heating' => 'Miejskie', 'building_floors' => '14']);

    $gratka = app(GratkaScraper::class)->parseOffer(networkFixture('gratka', 'offer'));

    expect($gratka)
        ->area->toBe(47.0)
        ->floor->toBe('2')
        ->is_private_owner->toBeTrue()
        ->and($gratka['description'])->toStartWith('Do wynajęcia 47 m² na drugim piętrze')
        ->and($gratka['description'])->toContain('czynsz dodatkowy 950 zł');
});

it('ta sama oferta z Morizon i Gratki to duplikat', function () {
    $morizon = Listing::factory()->create(['portal' => 'morizon', 'raw' => ['network_id' => '1543354408'], 'price' => 2000, 'area' => 48]);
    $gratka = Listing::factory()->create(['portal' => 'gratka', 'raw' => ['network_id' => '1543354408'], 'price' => 2100, 'area' => 70, 'city' => 'Inne']);

    expect(app(DuplicateDetector::class)->findOriginal($gratka)?->id)->toBe($morizon->id);
});
