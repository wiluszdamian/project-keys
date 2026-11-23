<?php

use App\Ai\Agents\ListingScoringAgent;
use App\Ai\InvalidAiResponseException;
use App\Jobs\AnalyzeListingJob;
use App\Models\Listing;
use App\Models\ListingAnalysis;
use App\Models\Search;
use App\Services\ListingScorer;
use App\Services\MarketStats;
use Laravel\Ai\Prompts\AgentPrompt;

function aiAnswer(array $overrides = []): array
{
    return array_replace_recursive([
        'score' => 8,
        'summary' => 'Dobra oferta na osiedlu Projektant.',
        'monthly_cost' => ['rent' => 2500, 'admin_fee' => 450, 'other_fees' => null, 'total' => 2950, 'note' => 'Media osobno.'],
        'sub_scores' => ['cost' => 7, 'location' => 9, 'standard' => 8, 'fit' => 8],
        'pros' => ['balkon', 'garaż', 'blisko parku', 'nowy blok', 'piąty punkt'],
        'red_flags' => [],
    ], $overrides);
}

beforeEach(function () {
    config(['ai.providers.opencode.key' => 'test-key']);
    $this->search = Search::factory()->create([
        'city' => 'Rzeszów', 'ai_preferences' => 'Osiedle Projektant najważniejsze.', 'min_score_to_notify' => 6,
    ]);
    $this->listing = Listing::factory()->create([
        'city' => 'Rzeszów', 'district' => 'Nowe Miasto', 'price' => 2500, 'rent_extra' => 450, 'area' => 60,
        'price_per_m2' => 42, 'description' => 'Mieszkanie <b>na osiedlu</b> Projektant. Media ok. 200 zł.',
    ]);
});

it('zapisuje ocenę z kosztem i ocenami cząstkowymi', function () {
    ListingScoringAgent::fake([aiAnswer()]);

    $analysis = app(ListingScorer::class)->score($this->search, $this->listing);

    expect($analysis->fresh())
        ->score->toBe(8)
        ->monthly_cost->toBe(2950)
        ->sub_scores->toEqualCanonicalizing(['cost' => 7, 'location' => 9, 'standard' => 8, 'fit' => 8])
        ->pros->toHaveCount(4)
        ->cost_breakdown->toMatchArray(['rent' => 2500, 'admin_fee' => 450, 'note' => 'Media osobno.'])
        ->model->toBe('deepseek-v4.1-flash')
        ->listing_content_hash->toBe($this->listing->content_hash);
});

it('przekazuje modelowi preferencje, koszt bazowy i rynek', function () {
    ListingScoringAgent::fake([aiAnswer()]);

    app(ListingScorer::class)->score($this->search, $this->listing);

    ListingScoringAgent::assertPrompted(fn (AgentPrompt $prompt) => $prompt->contains('Osiedle Projektant najważniejsze.')
        && $prompt->contains('koszt bazowy (najem + czynsz z portalu): 2950 zł')
        && $prompt->contains('Media ok. 200 zł')
        && $prompt->contains('danych rynkowych jest mało'));
});

it('ponawia raz przy złej odpowiedzi', function () {
    ListingScoringAgent::fake([aiAnswer(['score' => 15]), aiAnswer()]);

    $analysis = app(ListingScorer::class)->score($this->search, $this->listing);

    expect($analysis->score)->toBe(8);
    ListingScoringAgent::assertPromptedTimes(2);
});

it('po dwóch złych odpowiedziach rzuca wyjątek', function () {
    ListingScoringAgent::fake([aiAnswer(['summary' => '']), aiAnswer(['sub_scores' => ['cost' => 0]])]);

    expect(fn () => app(ListingScorer::class)->score($this->search, $this->listing))
        ->toThrow(InvalidAiResponseException::class);

    expect(ListingAnalysis::count())->toBe(0);
});

it('ponawia, gdy koszt jest pusty mimo ceny', function () {
    ListingScoringAgent::fake([
        aiAnswer(['monthly_cost' => ['rent' => null, 'admin_fee' => null, 'other_fees' => null, 'total' => null, 'note' => 'placeholder']]),
        aiAnswer(),
    ]);

    $analysis = app(ListingScorer::class)->score($this->search, $this->listing);

    expect($analysis->monthly_cost)->toBe(2950);
    ListingScoringAgent::assertPromptedTimes(2);
});

it('przy sprzedaży pusty koszt jest poprawny', function () {
    $this->listing->update(['deal_type' => 'sale', 'price' => 520000, 'rent_extra' => null]);
    $this->search->update(['deal_type' => 'sale']);
    ListingScoringAgent::fake([aiAnswer(['monthly_cost' => ['rent' => null, 'admin_fee' => null, 'other_fees' => null, 'total' => null, 'note' => 'Czynsz nieznany.']])]);

    $analysis = app(ListingScorer::class)->score($this->search->fresh(), $this->listing->fresh());

    expect($analysis->monthly_cost)->toBeNull();
    ListingScoringAgent::assertPromptedTimes(1);
});

it('wysyła reasoning_effort z konfiguracji', function () {
    config(['scoring.reasoning_effort' => 'low']);

    expect((new ListingScoringAgent)->providerOptions('opencode'))->toBe(['reasoning_effort' => 'low']);

    config(['scoring.reasoning_effort' => null]);
    expect((new ListingScoringAgent)->providerOptions('opencode'))->toBe([]);
});

it('job nie przerywa łańcucha przy złej odpowiedzi', function () {
    ListingScoringAgent::fake([aiAnswer(['score' => 'dużo']), aiAnswer(['score' => null])]);

    AnalyzeListingJob::dispatchSync($this->search, $this->listing);

    expect(ListingAnalysis::count())->toBe(0);
});

it('job nie ocenia drugi raz tej samej treści', function () {
    ListingScoringAgent::fake([aiAnswer(), aiAnswer(['score' => 3])]);

    AnalyzeListingJob::dispatchSync($this->search, $this->listing);
    AnalyzeListingJob::dispatchSync($this->search, $this->listing);
    ListingScoringAgent::assertPromptedTimes(1);

    $this->listing->update(['content_hash' => 'nowa-tresc']);
    AnalyzeListingJob::dispatchSync($this->search, $this->listing->fresh());

    expect(ListingAnalysis::sole()->score)->toBe(3);
});

it('bez klucza nic nie wywołuje', function () {
    config(['ai.providers.opencode.key' => null]);
    ListingScoringAgent::fake();

    AnalyzeListingJob::dispatchSync($this->search, $this->listing);

    ListingScoringAgent::assertNeverPrompted();
});

it('liczy medianę dla dzielnicy, a przy małej próbie dla miasta', function () {
    Listing::factory()->count(10)->create(['city' => 'Rzeszów', 'district' => 'Zalesie', 'price_per_m2' => 50, 'price' => 2500, 'rent_extra' => 500, 'area' => 50]);
    Listing::factory()->count(3)->create(['city' => 'Rzeszów', 'district' => 'Nowe Miasto', 'price_per_m2' => 30]);

    $zalesie = app(MarketStats::class)->forListing(Listing::where('district', 'Zalesie')->first());
    $noweMiasto = app(MarketStats::class)->forListing($this->listing);

    expect($zalesie)->toMatchArray(['scope' => 'dzielnica Zalesie', 'median_price_per_m2' => 50, 'median_total_cost_per_m2' => 60, 'sample_size' => 10, 'enough_data' => true])
        ->and($noweMiasto)->toMatchArray(['scope' => 'miasto Rzeszów', 'sample_size' => 14, 'enough_data' => true]);
});

it('ai:usage pokazuje sumę tokenów', function () {
    ListingAnalysis::factory()->create(['input_tokens' => 1200, 'output_tokens' => 300]);

    $this->artisan('ai:usage')->expectsOutputToContain('1 200')->assertSuccessful();
});

it('job odłącza ofertę, która po pobraniu opisu okazuje się do wykończenia', function () {
    ListingScoringAgent::fake([aiAnswer()]);
    $this->search->update(['move_in_ready' => true]);
    $this->search->listings()->attach($this->listing, ['matched_at' => now()]);
    $this->listing->update(['description' => 'Mieszkanie w stanie deweloperskim, bez mebli.']);

    AnalyzeListingJob::dispatchSync($this->search, $this->listing->fresh());

    ListingScoringAgent::assertNeverPrompted();
    expect($this->search->listings()->count())->toBe(0)
        ->and(ListingAnalysis::count())->toBe(0);
});

it('podaje modelowi rynek, meble i wymagania wyszukiwania', function () {
    ListingScoringAgent::fake([aiAnswer()]);
    $this->search->update(['market' => 'secondary', 'move_in_ready' => true]);
    $this->listing->update(['raw' => ['enriched_at' => now()->toIso8601String(), 'market' => 'secondary'], 'description' => 'Mieszkanie umeblowane.']);

    app(ListingScorer::class)->score($this->search, $this->listing->fresh());

    ListingScoringAgent::assertPrompted(fn (AgentPrompt $prompt) => $prompt->contains('- rynek: tylko wtórny')
        && $prompt->contains('mieszkanie musi być gotowe do zamieszkania')
        && $prompt->contains('gotowe do zamieszkania (z danych portalu i opisu): tak')
        && $prompt->contains('- rynek: wtórny')
        && $prompt->contains('umeblowane (z danych portalu i opisu): tak'));
});

it('podaje modelowi polubione i odrzucone oferty z notatkami', function () {
    ListingScoringAgent::fake([aiAnswer()]);
    Listing::factory()->create(['title' => 'Mieszkanie przy parku', 'status' => 'favorite', 'note' => 'Ulubiona: duży balkon']);
    Listing::factory()->create(['title' => 'Parter przy torach', 'status' => 'rejected', 'note' => 'Odrzucona: parter i hałas', 'floor' => 'parter']);
    Listing::factory()->create(['title' => 'Sprzedaż', 'deal_type' => 'sale', 'status' => 'rejected']);

    app(ListingScorer::class)->score($this->search, $this->listing);

    ListingScoringAgent::assertPrompted(fn (AgentPrompt $prompt) => $prompt->contains('## Decyzje osoby w panelu')
        && $prompt->contains('- ulubiona: Mieszkanie przy parku')
        && $prompt->contains('Notatka osoby: Ulubiona: duży balkon')
        && $prompt->contains('- odrzucona: Parter przy torach')
        && $prompt->contains('piętro parter')
        && ! $prompt->contains('Sprzedaż ('));
});

it('bez nauki na decyzjach prompt nie ma przykładów', function () {
    ListingScoringAgent::fake([aiAnswer()]);
    config(['scoring.learn_from_decisions' => false]);
    Listing::factory()->create(['status' => 'rejected']);

    app(ListingScorer::class)->score($this->search, $this->listing);

    ListingScoringAgent::assertPrompted(fn (AgentPrompt $prompt) => ! $prompt->contains('Decyzje osoby'));
});
