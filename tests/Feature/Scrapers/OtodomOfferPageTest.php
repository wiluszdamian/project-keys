<?php

use App\Ai\Agents\ListingScoringAgent;
use App\Enums\DealType;
use App\Jobs\AnalyzeListingJob;
use App\Models\Listing;
use App\Models\Search;
use App\Scrapers\Data\ListingData;
use App\Scrapers\OtodomScraper;
use App\Scrapers\Support\TextParser;
use App\Services\ListingUpserter;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Sleep;
use Laravel\Ai\Prompts\AgentPrompt;

function offerFixture(string $name): string
{
    return file_get_contents(base_path("tests/Fixtures/otodom/{$name}.html"));
}

function otodomListingData(array $overrides = []): ListingData
{
    return new ListingData(...array_merge([
        'portal' => 'otodom',
        'externalId' => '68600001',
        'url' => 'https://www.otodom.pl/pl/oferta/3-pokojowe-os-projektant-ID4DQFa',
        'title' => '3-POKOJOWE MIESZKANIE | OS. PROJEKTANT',
        'dealType' => DealType::Rent,
        'price' => 2900,
        'area' => 60.0,
        'rooms' => 3,
        'city' => 'Rzeszów',
        'district' => 'Franciszka Kotuli',
        'raw' => ['id' => 68600001],
    ], $overrides));
}

beforeEach(fn () => Sleep::fake());

it('czyta opis, kaucję i fakty ze strony ogłoszenia', function () {
    $offer = app(OtodomScraper::class)->parseOffer(offerFixture('offer_no_fee'));

    expect($offer['description'])->toContain('570 zł - czynsz administracyjny')
        ->and($offer['description'])->toContain('Całkowity miesięczny koszt najmu: 3 470 zł')
        ->and($offer['description'])->not->toContain('<')
        ->and($offer['rent_extra'])->toBeNull()
        ->and($offer['details'])->toMatchArray(['deposit' => 2900, 'build_year' => '2018', 'heating' => 'gas']);
});

it('czyta czynsz, gdy portal go podaje', function () {
    expect(app(OtodomScraper::class)->parseOffer(offerFixture('offer_with_fee'))['rent_extra'])->toBe(720);
});

it('uzupełnia ofertę danymi ze strony', function () {
    Http::fake(['www.otodom.pl/pl/oferta/*' => Http::response(offerFixture('offer_no_fee'))]);
    $listing = app(ListingUpserter::class)->upsert(otodomListingData())->listing;
    $hash = $listing->content_hash;

    expect(app(OtodomScraper::class)->enrich($listing))->toBeTrue();

    $listing->refresh();
    expect($listing->description)->toContain('osiedlu Projektant')
        ->and($listing->raw['details']['deposit'])->toBe(2900)
        ->and($listing->raw['id'])->toBe(68600001)
        ->and($listing->content_hash)->not->toBe($hash);
});

it('kolejny przebieg nie kasuje opisu ani nie zmienia treści', function () {
    Http::fake(['www.otodom.pl/pl/oferta/*' => Http::response(offerFixture('offer_with_fee'))]);
    $upserter = app(ListingUpserter::class);
    $listing = $upserter->upsert(otodomListingData())->listing;
    app(OtodomScraper::class)->enrich($listing);
    $enriched = $listing->fresh();

    $result = $upserter->upsert(otodomListingData());

    expect($result->contentChanged)->toBeFalse()
        ->and($result->listing->fresh())
        ->description->toBe($enriched->description)
        ->rent_extra->toBe(720)
        ->content_hash->toBe($enriched->content_hash)
        ->and($result->listing->fresh()->raw['details'])->toBe($enriched->raw['details']);
});

it('przed oceną AI pobiera stronę oferty Otodom', function () {
    config(['ai.providers.opencode.key' => 'test-key']);
    Http::fake(['www.otodom.pl/pl/oferta/*' => Http::response(offerFixture('offer_no_fee'))]);
    ListingScoringAgent::fake();
    $search = Search::factory()->create(['city' => 'Rzeszów']);
    $listing = app(ListingUpserter::class)->upsert(otodomListingData())->listing;

    AnalyzeListingJob::dispatchSync($search, $listing);

    ListingScoringAgent::assertPrompted(fn (AgentPrompt $prompt) => $prompt->contains('570 zł - czynsz administracyjny')
        && $prompt->contains('kaucja: 2900 zł')
        && $prompt->contains('rok budowy: 2018'));
    expect($listing->analyses()->sole()->listing_content_hash)->toBe($listing->fresh()->content_hash);
});

it('blokada strony oferty nie blokuje oceny', function () {
    config(['ai.providers.opencode.key' => 'test-key']);
    Http::fake(['www.otodom.pl/pl/oferta/*' => Http::response('', 403)]);
    ListingScoringAgent::fake();
    $listing = app(ListingUpserter::class)->upsert(otodomListingData())->listing;

    AnalyzeListingJob::dispatchSync(Search::factory()->create(), $listing);

    ListingScoringAgent::assertPrompted(fn (AgentPrompt $prompt) => $prompt->contains('portal nie podał opisu'));
});

it('oferta OLX z opisem nie pobiera strony', function () {
    config(['ai.providers.opencode.key' => 'test-key']);
    Http::fake();
    ListingScoringAgent::fake();
    $listing = Listing::factory()->create(['portal' => 'olx', 'description' => 'Opis z listy.']);

    AnalyzeListingJob::dispatchSync(Search::factory()->create(), $listing);

    Http::assertNotSent(fn ($request) => str_contains($request->url(), 'olx.pl'));
    ListingScoringAgent::assertPromptedTimes(1);
});

it('czyta współrzędne ze strony ogłoszenia', function () {
    expect(TextParser::coordinates(offerFixture('offer_with_fee')))->toBe([50.028107, 22.000294]);
});
