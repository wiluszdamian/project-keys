<?php

namespace App\Jobs;

use App\Ai\Agents\ViewingQuestionsAgent;
use App\Enums\DealType;
use App\Models\Listing;
use App\Notifications\PanelNotifier;
use App\Services\LeaseTerms;
use App\Services\ListingFacts;
use App\Services\ListingScorer;
use App\Services\TimeOnMarket;
use App\Services\ViewingQuestions;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\Middleware\RateLimited;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

/**
 * Pytania od AI na oglądanie jednej oferty. Zapisuje je w viewing_questions i daje znać w panelu.
 */
class SuggestViewingQuestionsJob implements ShouldQueue
{
    use Queueable;

    public int $timeout = 150;

    // Limit wywołań AI na minutę odkłada job z powrotem do kolejki.
    public int $tries = 20;

    public array $backoff = [10, 60, 300];

    public function __construct(public Listing $listing)
    {
        $this->onQueue('ai');
    }

    public function middleware(): array
    {
        return [new RateLimited('ai')];
    }

    public function handle(ListingScorer $scorer, PanelNotifier $panel): void
    {
        if (! ListingScorer::isEnabled()) {
            return;
        }

        $listing = $this->listing->fresh(['analyses', 'searches', 'duplicates', 'originalListing']);
        $scorer->countCall();

        try {
            $data = ViewingQuestionsAgent::make()->prompt(self::prompt($listing))->toArray();
        } catch (Throwable $exception) {
            $scorer->countError();
            Log::warning("Pytania na oglądanie oferty #{$listing->id} nie wyszły: {$exception->getMessage()}");

            throw $exception;
        }

        $questions = collect($data['questions'] ?? [])
            ->filter(fn ($row) => is_array($row) && filled($row['question'] ?? null))
            ->take(8)
            ->map(fn (array $row) => ['question' => Str::limit(trim((string) $row['question']), 300), 'why' => Str::limit(trim((string) ($row['why'] ?? '')), 300) ?: null])
            ->values()
            ->all();

        $listing->forceFill(['viewing_questions' => $questions, 'viewing_questions_at' => now()])->save();
        $panel->viewingQuestions($listing, count($questions));
    }

    public static function prompt(Listing $listing): string
    {
        $rent = $listing->deal_type === DealType::Rent;
        $analysis = $listing->analyses->sortByDesc('score')->first();

        $lines = [
            'Dzisiaj jest '.now()->format('Y-m-d').'.',
            '',
            '## Preferencje osoby',
            ...($listing->searches->pluck('ai_preferences')->filter()->unique()->map(fn (string $text) => '- '.Str::squish($text))->all() ?: ['- brak opisu preferencji']),
            filled($listing->note) ? '- notatka osoby o tej ofercie: '.Str::squish((string) $listing->note) : null,
            '',
            '## Ogłoszenie',
            '- typ: '.($rent ? 'wynajem' : 'sprzedaż'),
            "- tytuł: {$listing->title}",
            '- cena: '.($listing->price ? $listing->price.' zł'.($rent ? ' miesięcznie' : '') : 'nie podano'),
            '- czynsz administracyjny: '.($listing->rent_extra !== null ? $listing->rent_extra.' zł' : 'nie podano'),
            '- metraż: '.($listing->area ? (float) $listing->area.' m2' : 'nie podano'),
            '- pokoje / piętro: '.($listing->rooms ?? '?').' / '.($listing->floor ?? '?'),
            '- lokalizacja: '.collect([$listing->city, $listing->district, $listing->street])->filter()->unique()->implode(', '),
            ...collect(ListingFacts::labels($listing))->map(fn (string $value, string $label) => '- '.mb_strtolower($label).': '.$value)->values()->all(),
            ...collect(LeaseTerms::labels($listing))->map(fn (string $value, string $label) => '- '.mb_strtolower($label).': '.$value)->values()->all(),
            ($onMarket = TimeOnMarket::describe($listing)) ? '- jak długo wisi: '.$onMarket : null,
            '- opis: '.($listing->description ? Str::limit(Str::squish($listing->description), (int) config('scoring.description_limit')) : 'portal nie podał opisu'),
            '',
            '## Ocena AI',
            $analysis ? "- ocena {$analysis->score}/10: {$analysis->summary}" : '- brak oceny',
            ...collect($analysis?->red_flags ?? [])->map(fn (string $flag) => "- uwaga: {$flag}")->all(),
            ...collect($listing->risk_flags ?? [])->map(fn (array $flag) => '- ryzyko wykryte automatycznie: '.($flag['message'] ?? ''))->all(),
            '',
            '## Pytania, które już są na liście',
            ...array_map(fn (string $question) => "- {$question}", [...ViewingQuestions::forListing($listing), ...ViewingQuestions::base($listing)]),
        ];

        return implode("\n", array_filter($lines, fn ($line) => $line !== null));
    }
}
