<?php

use App\Ai\Agents\ViewingQuestionsAgent;
use App\Enums\ListingStatus;
use App\Jobs\SuggestViewingQuestionsJob;
use App\Models\Listing;
use App\Models\ListingAnalysis;
use App\Models\Search;
use App\Models\User;
use App\Notifications\PanelNotifier;
use App\Services\ListingScorer;
use App\Services\ViewingQuestions;
use App\Support\ViewingCalendar;
use Illuminate\Support\Facades\Queue;
use Laravel\Ai\Prompts\AgentPrompt;

it('pyta o to, czego ogłoszenie nie podaje', function () {
    $listing = Listing::factory()->create([
        'description' => 'Mieszkanie 2 pokoje. Kaucja 3000 zł. Ogrzewanie miejskie. Najem okazjonalny.',
        'rent_extra' => null, 'is_private_owner' => false, 'floor' => '4',
        'risk_flags' => [['type' => 'prepayment', 'message' => 'Prośba o przedpłatę przed obejrzeniem.']],
    ]);
    ListingAnalysis::factory()->create(['listing_id' => $listing->id, 'red_flags' => ['Brak informacji o mediach']]);

    $questions = ViewingQuestions::forListing($listing->fresh());

    expect($questions)
        ->toContain('Ile wynosi czynsz administracyjny i co obejmuje?')
        ->toContain('Kto płaci za notariusza przy najmie okazjonalnym i czy trzeba wskazać adres zastępczy?')
        ->toContain('Czy w budynku jest winda?')
        ->toContain('Czy jest prowizja dla biura i ile wynosi?')
        ->toContain('Wyjaśnij: prośba o przedpłatę przed obejrzeniem.')
        ->toContain('Dopytaj (uwaga z oceny AI): brak informacji o mediach.')
        ->not->toContain('Jaka jest kaucja?')
        ->not->toContain('Jakie jest ogrzewanie i ile kosztuje miesięcznie zimą?');
});

it('ma osobną stałą listę dla najmu i sprzedaży', function () {
    expect(ViewingQuestions::base(Listing::factory()->make()))->toContain('Jakie są warunki zwrotu kaucji i w jakim terminie?')
        ->and(ViewingQuestions::base(Listing::factory()->make(['deal_type' => 'sale'])))->toContain('Numer księgi wieczystej: sprawdź właściciela, hipotekę i służebności (dział III i IV).');
});

it('tworzy plik kalendarza z terminem w UTC, adresem i przypomnieniem', function () {
    $listing = Listing::factory()->create(['title' => 'Mieszkanie, 3 pokoje', 'street' => 'ul. Lwowska', 'city' => 'Rzeszów', 'district' => null]);
    $listing->forceFill(['viewing_at' => '2026-12-01 17:30:00'])->save();

    $ics = ViewingCalendar::ics($listing->fresh());

    expect($ics)->toContain('BEGIN:VEVENT')
        // Godzina z panelu (strefa aplikacji) zapisana w UTC.
        ->toContain('DTSTART:'.now()->parse('2026-12-01 17:30:00')->utc()->format('Ymd\\THis\\Z'))
        ->toContain('DTEND:'.now()->parse('2026-12-01 18:15:00')->utc()->format('Ymd\\THis\\Z'))
        ->toContain('SUMMARY:Oglądanie: Mieszkanie\, 3 pokoje')
        ->toContain('LOCATION:ul. Lwowska\, Rzeszów')
        ->toContain('TRIGGER:-PT1H')
        ->and(collect(explode("\r\n", $ics))->every(fn (string $line) => strlen($line) <= 75))->toBeTrue();
});

it('status Oglądanie zleca AI pytania, ale tylko raz', function () {
    config(['ai.providers.opencode.key' => 'test-key']);
    Queue::fake();
    $listing = Listing::factory()->create();

    $listing->applyStatus(ListingStatus::Viewing);
    $listing->forceFill(['viewing_questions_at' => now()])->save();
    $listing->applyStatus(ListingStatus::Viewing);

    Queue::assertPushed(SuggestViewingQuestionsJob::class, 1);
});

it('zapisuje pytania od AI i daje znać w panelu', function () {
    config(['ai.providers.opencode.key' => 'test-key']);
    $user = User::factory()->create();
    $search = Search::factory()->create(['ai_preferences' => 'Mam psa, ważny parking.']);
    $listing = Listing::factory()->create(['description' => 'Mieszkanie przy parku.']);
    $listing->searches()->attach($search, ['matched_at' => now()]);

    ViewingQuestionsAgent::fake([['questions' => [
        ['question' => 'Czy można mieszkać z psem?', 'why' => 'Masz psa, a opis o tym nie mówi.'],
        ['question' => '', 'why' => 'puste pytanie odpada'],
    ]]]);

    (new SuggestViewingQuestionsJob($listing))->handle(app(ListingScorer::class), app(PanelNotifier::class));

    expect(ViewingQuestions::fromAi($listing->fresh()))->toBe([['question' => 'Czy można mieszkać z psem?', 'why' => 'Masz psa, a opis o tym nie mówi.']])
        ->and($listing->fresh()->viewing_questions_at)->not->toBeNull()
        ->and($user->notifications()->count())->toBe(1);

    ViewingQuestionsAgent::assertPrompted(fn (AgentPrompt $prompt) => $prompt->contains('Mam psa, ważny parking.')
        && $prompt->contains('Pytania, które już są na liście'));
});

it('przypomina o oglądaniu raz, na dwie godziny przed', function () {
    $user = User::factory()->create();
    $soon = Listing::factory()->create();
    $soon->forceFill(['viewing_at' => now()->addHour()])->save();
    $later = Listing::factory()->create();
    $later->forceFill(['viewing_at' => now()->addDay()])->save();

    $this->artisan('viewings:remind')->assertSuccessful();
    $this->artisan('viewings:remind')->assertSuccessful();

    expect($user->notifications()->count())->toBe(1)
        ->and($soon->fresh()->viewing_reminded_at)->not->toBeNull()
        ->and($later->fresh()->viewing_reminded_at)->toBeNull();
});
