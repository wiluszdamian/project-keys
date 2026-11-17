<?php

use App\Enums\DealType;
use App\Models\Listing;
use App\Models\Search;
use App\Scrapers\Data\ListingData;
use App\Services\ListingUpserter;

function upsertData(array $overrides = []): ListingData
{
    return new ListingData(...array_merge([
        'portal' => 'otodom',
        'externalId' => '123',
        'url' => 'https://example.test/123',
        'title' => 'Mieszkanie 50 m2',
        'dealType' => DealType::Rent,
        'price' => 3000,
        'area' => 50.0,
        'rooms' => 2,
        'raw' => ['id' => 123],
    ], $overrides));
}

it('dodaje nowe ogłoszenie', function () {
    $result = app(ListingUpserter::class)->upsert(upsertData());

    expect($result->isNew)->toBeTrue()
        ->and($result->priceChanged)->toBeFalse()
        ->and($result->listing->fresh())
        ->price_per_m2->toBe(60)
        ->content_hash->toHaveLength(40)
        ->raw->toBe(['id' => 123])
        ->first_seen_at->not->toBeNull();
});

it('aktualizuje istniejące i zapisuje zmianę ceny', function () {
    $upserter = app(ListingUpserter::class);
    $first = $upserter->upsert(upsertData());

    $this->travel(1)->hours();
    $second = $upserter->upsert(upsertData(['price' => 2800, 'title' => 'Mieszkanie 50 m2, obniżka']));

    expect(Listing::count())->toBe(1)
        ->and($second->isNew)->toBeFalse()
        ->and($second->priceChanged)->toBeTrue()
        ->and($second->oldPrice)->toBe(3000);

    $listing = $first->listing->fresh();
    expect($listing->price)->toBe(2800)
        ->and($listing->title)->toBe('Mieszkanie 50 m2, obniżka')
        ->and($listing->last_seen_at->gt($listing->first_seen_at))->toBeTrue()
        ->and($listing->priceChanges)->toHaveCount(1)
        ->and($listing->priceChanges->first()->only(['old_price', 'new_price']))->toBe(['old_price' => 3000, 'new_price' => 2800]);
});

it('ta sama cena nie tworzy wpisu w historii', function () {
    $upserter = app(ListingUpserter::class);
    $upserter->upsert(upsertData());
    $result = $upserter->upsert(upsertData());

    expect($result->priceChanged)->toBeFalse()
        ->and($result->listing->priceChanges()->count())->toBe(0);
});

it('to samo ID na innym portalu to osobne ogłoszenie', function () {
    $upserter = app(ListingUpserter::class);
    $upserter->upsert(upsertData());
    $result = $upserter->upsert(upsertData(['portal' => 'olx']));

    expect($result->isNew)->toBeTrue()->and(Listing::count())->toBe(2);
});

it('łączy ogłoszenie z wyszukiwaniem tylko raz', function () {
    $search = Search::factory()->create();
    $upserter = app(ListingUpserter::class);
    $listing = $upserter->upsert(upsertData())->listing;

    expect($upserter->attach($search, $listing))->toBeTrue()
        ->and($upserter->attach($search, $listing))->toBeFalse()
        ->and($search->listings()->count())->toBe(1);
});
