<?php

namespace App\Services;

use App\Ai\Agents\ListingScoringAgent;
use App\Ai\InvalidAiResponseException;
use App\Enums\DealType;
use App\Models\Listing;
use App\Models\ListingAnalysis;
use App\Models\RcnTransaction;
use App\Models\Search;
use App\Services\Geo\CommuteCalculator;
use App\Services\Geo\Infrastructure;
use App\Services\Rcn\TransactionComparator;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Wywołuje model, sprawdza odpowiedź i zapisuje ocenę w listing_analyses.
 */
class ListingScorer
{
    private const SUB_SCORES = ['cost', 'location', 'standard', 'fit'];

    public function __construct(private MarketStats $market, private CommuteCalculator $commutes, private DecisionExamples $decisions) {}

    /**
     * Czy ocena AI jest włączona i ma klucz.
     */
    public static function isEnabled(): bool
    {
        $provider = config('ai.default');

        return config('scoring.enabled') && filled(config("ai.providers.{$provider}.key"));
    }

    public function score(Search $search, Listing $listing): ListingAnalysis
    {
        $prompt = $this->prompt($search, $listing);
        $errors = [];

        // Niepoprawna odpowiedź: jedna ponowna próba.
        for ($attempt = 1; $attempt <= 2; $attempt++) {
            $this->countCall();
            $response = ListingScoringAgent::make()->prompt($prompt);
            $data = $response->toArray();
            $errors = $this->validate($data, $listing);

            if ($errors === []) {
                return $this->save($search, $listing, $data, $response->usage->inputTokens ?? null, $response->usage->outputTokens ?? null);
            }

            Log::warning("Niepoprawna odpowiedź AI dla oferty #{$listing->id}", ['errors' => $errors, 'attempt' => $attempt]);
        }

        $this->countError();

        throw new InvalidAiResponseException('Niepoprawna odpowiedź AI: '.implode('; ', $errors));
    }

    public function prompt(Search $search, Listing $listing): string
    {
        $rent = $search->deal_type === DealType::Rent;
        $baseCost = $listing->price !== null ? $listing->price + ($rent ? (int) $listing->rent_extra : 0) : null;
        $market = $this->market->forListing($listing);
        $features = ListingFeatures::of($listing);

        $lines = [
            'Dzisiaj jest '.now()->format('Y-m-d').'.',
            '',
            '## Preferencje osoby',
            $search->ai_preferences ?: 'Brak dodatkowych preferencji.',
            '',
            ...$this->decisions->lines($search, $listing),
            '## Filtry wyszukiwania',
            '- typ: '.($rent ? 'wynajem' : 'sprzedaż'),
            "- miasto: {$search->city}",
            '- dzielnice: '.($search->districts ? implode(', ', $search->districts) : 'całe miasto'),
            '- cena: '.$this->range($search->price_min, $search->price_max, 'zł'),
            '- metraż: '.$this->range($search->area_min, $search->area_max, 'm2'),
            '- pokoje: '.$this->range($search->rooms_min, $search->rooms_max, ''),
            $search->market ? '- rynek: '.($search->market === RcnTransaction::PRIMARY ? 'tylko pierwotny' : 'tylko wtórny') : null,
            $search->move_in_ready ? '- mieszkanie musi być gotowe do zamieszkania: podłogi, kuchnia i łazienka zrobione. Meble są plusem, ale nie są konieczne. Stan deweloperski, do wykończenia albo do remontu odpada, a brak informacji o stanie to minus.' : null,
            $search->exclude_visualizations ? '- bez ofert ze zdjęciami-wizualizacjami' : null,
            '',
            '## Ogłoszenie',
            "- portal: {$listing->portal}",
            "- tytuł: {$listing->title}",
            '- cena: '.$this->value($listing->price, 'zł'.($rent ? ' miesięcznie' : '')),
            '- czynsz administracyjny z portalu: '.$this->value($listing->rent_extra, 'zł'),
            $rent ? '- koszt bazowy (najem + czynsz z portalu): '.$this->value($baseCost, 'zł').($listing->rent_extra === null ? ' (czynsz nieznany, sprawdź opis)' : '') : null,
            '- metraż: '.$this->value($listing->area !== null ? (float) $listing->area : null, 'm2'),
            '- cena za m2: '.$this->value($listing->price_per_m2, 'zł'),
            $rent && $baseCost && $listing->area > 0 ? '- koszt bazowy za m2: '.round($baseCost / $listing->area).' zł' : null,
            '- pokoje: '.$this->value($listing->rooms),
            '- piętro: '.$this->value($listing->floor),
            '- dzielnica: '.$this->value($listing->district),
            '- ulica: '.$this->value($listing->street),
            '- ogłoszeniodawca: '.match ($listing->is_private_owner) {
                true => 'prywatny',
                false => 'agencja lub firma',
                null => 'nie wiadomo',
            },
            '- rynek: '.$features->marketLabel(),
            '- gotowe do zamieszkania (z danych portalu i opisu): '.$features->readyLabel(),
            '- umeblowane (z danych portalu i opisu): '.$features->furnishedLabel(),
            $features->visualizations ? '- zdjęcia to wizualizacje, nie prawdziwe mieszkanie' : null,
            ...$this->details($listing),
            ...collect(ListingFacts::labels($listing))->map(fn (string $value, string $label) => '- '.mb_strtolower($label).' (z portalu albo opisu): '.$value)->values()->all(),
            ...($rent ? collect(LeaseTerms::labels($listing))->except('Ogłoszeniodawca')->map(fn (string $value, string $label) => '- '.mb_strtolower($label).' (odczytane automatycznie): '.$value)->values()->all() : []),
            ($onMarket = TimeOnMarket::describe($listing)) ? '- jak długo wisi: '.$onMarket : null,
            '- opis: '.($listing->description ? Str::limit(Str::squish($listing->description), config('scoring.description_limit')) : 'portal nie podał opisu'),
            '',
            '## Dojazd (trasa z naszego serwera, bez korków)',
            ...($this->commuteLines($listing)),
            '',
            ...(($infrastructure = Infrastructure::lines($listing)) ? ['## Okolica (najbliższe punkty, odległość drogą)', ...array_map(fn (string $line) => "- {$line}", $infrastructure), ''] : []),
            '## Sygnały ryzyka wykryte automatycznie',
            ...($listing->risk_flags ? array_map(fn (array $flag) => "- {$flag['message']}", $listing->risk_flags) : ['- brak']),
            '',
            ...$this->transactionLines($listing),
            '## Rynek (nasza baza, ostatnie '.MarketStats::DAYS.' dni, '.$market['scope'].')',
            '- mediana ceny za m2: '.$this->value($market['median_price_per_m2'], 'zł'),
            $rent ? '- mediana kosztu (najem + czynsz) za m2: '.$this->value($market['median_total_cost_per_m2'], 'zł') : null,
            "- liczba ofert w próbie: {$market['sample_size']}",
            $market['enough_data'] ? null : '- uwaga: danych rynkowych jest mało, nie oceniaj ceny surowo',
        ];

        return implode("\n", array_filter($lines, fn ($line) => $line !== null));
    }

    /**
     * Ceny transakcyjne z RCN dla ofert sprzedaży (sekcja z pustą linią na końcu albo nic).
     *
     * @return list<string>
     */
    private function transactionLines(Listing $listing): array
    {
        $comparison = $listing->transaction_comparison;

        if ($listing->deal_type !== DealType::Sale || ! $comparison) {
            return [];
        }

        if (($comparison['status'] ?? null) === TransactionComparator::TOO_FEW) {
            return ['## Ceny transakcyjne (Rejestr Cen Nieruchomości)', '- za mało transakcji w okolicy do porównania', ''];
        }

        return [
            '## Ceny transakcyjne (Rejestr Cen Nieruchomości, prawdziwe ceny sprzedaży)',
            '- mediana: '.$comparison['median_price_per_m2'].' zł za m2 ('.TransactionComparator::details($comparison).')',
            '- połowa transakcji między '.$comparison['p25_price_per_m2'].' a '.$comparison['p75_price_per_m2'].' zł za m2',
            '- oferta: '.$comparison['offer_price_per_m2'].' zł za m2, różnica '.($comparison['diff_percent'] > 0 ? '+' : '').$comparison['diff_percent'].'%',
            empty($comparison['approximate']) ? null : '- uwaga: położenie oferty znane tylko z dokładnością do miasta, porównanie z całym miastem jest mało dokładne',
            '- porównanie nie uwzględnia wieku i standardu budynku',
            '',
        ];
    }

    /**
     * @return list<string>
     */
    public function validate(array $data, ?Listing $listing = null): array
    {
        $errors = [];

        if (! is_int($data['score'] ?? null) || $data['score'] < 1 || $data['score'] > 10) {
            $errors[] = 'score musi być liczbą 1-10';
        }

        if (! is_string($data['summary'] ?? null) || trim($data['summary']) === '') {
            $errors[] = 'summary jest puste';
        }

        foreach (['pros', 'red_flags'] as $list) {
            if (! is_array($data[$list] ?? null)) {
                $errors[] = "{$list} musi być listą";
            }
        }

        foreach (self::SUB_SCORES as $key) {
            $value = $data['sub_scores'][$key] ?? null;

            if (! is_int($value) || $value < 1 || $value > 10) {
                $errors[] = "sub_scores.{$key} musi być liczbą 1-10";
            }
        }

        $cost = $data['monthly_cost'] ?? null;
        $total = $cost['total'] ?? null;

        if (! is_array($cost)) {
            $errors[] = 'brak monthly_cost';
        } elseif ($total !== null && (! is_int($total) || $total < 0)) {
            $errors[] = 'monthly_cost.total musi być liczbą albo null';
        } elseif ($listing?->deal_type === DealType::Rent && $listing->price !== null && $total === null && ($cost['rent'] ?? null) === null) {
            // Wynajem z ceną: koszt nie może być pusty. Przy sprzedaży koszt to sam czynsz i bywa nieznany.
            $errors[] = 'monthly_cost jest pusty, choć oferta ma cenę';
        }

        $note = trim((string) ($cost['note'] ?? ''));
        if ($note === '' || mb_strtolower($note) === 'placeholder') {
            $errors[] = 'monthly_cost.note jest puste';
        }

        return $errors;
    }

    private function save(Search $search, Listing $listing, array $data, ?int $inputTokens, ?int $outputTokens): ListingAnalysis
    {
        $strings = fn (array $items) => array_values(array_slice(array_filter(array_map(
            fn ($item) => is_string($item) ? trim($item) : null,
            $items,
        )), 0, 4));

        $cost = $data['monthly_cost'] ?? [];

        return ListingAnalysis::updateOrCreate(
            ['listing_id' => $listing->id, 'search_id' => $search->id],
            [
                'model' => config('ai.providers.'.config('ai.default').'.models.text.default') ?? 'unknown',
                'score' => $data['score'],
                'monthly_cost' => $cost['total'] ?? null,
                'cost_breakdown' => [
                    'rent' => $cost['rent'] ?? null,
                    'admin_fee' => $cost['admin_fee'] ?? null,
                    'other_fees' => $cost['other_fees'] ?? null,
                    'note' => $cost['note'] ?? null,
                ],
                'sub_scores' => array_intersect_key($data['sub_scores'], array_flip(self::SUB_SCORES)),
                'summary' => trim($data['summary']),
                'pros' => $strings($data['pros']),
                'red_flags' => $strings($data['red_flags']),
                'input_tokens' => $inputTokens,
                'output_tokens' => $outputTokens,
                'listing_content_hash' => $listing->content_hash,
            ],
        );
    }

    /**
     * Liczniki z ostatniej godziny dla alertu o błędach AI.
     */
    public function countError(): void
    {
        $this->increment('errors');
    }

    public function countCall(): void
    {
        $this->increment('calls');
    }

    private function increment(string $what): void
    {
        $key = "ai:{$what}:".now()->format('YmdH');
        Cache::add($key, 0, now()->addHours(3));
        Cache::increment($key);
    }

    /**
     * Dane ze strony ogłoszenia (Otodom): kaucja, rok budowy, ogrzewanie.
     *
     * @return list<string>
     */
    /**
     * @return list<string>
     */
    private function commuteLines(Listing $listing): array
    {
        $lines = array_map(fn (string $line) => "- {$line}", $this->commutes->describe($listing));

        if ($lines === []) {
            return ['- brak danych (brak adresu oferty albo miejsc dojazdu)'];
        }

        if (in_array($listing->geo_precision, ['district', 'city'], true)) {
            $lines[] = '- uwaga: położenie przybliżone ('.($listing->geo_precision === 'city' ? 'środek miasta' : 'środek dzielnicy').'), czasy mogą się różnić';
        }

        return $lines;
    }

    private function details(Listing $listing): array
    {
        $details = $listing->raw['details'] ?? [];
        $labels = [
            'deposit' => ['kaucja', 'zł'],
            'build_year' => ['rok budowy', ''],
            'heating' => ['ogrzewanie', ''],
            'building_floors' => ['pięter w budynku', ''],
            'free_from' => ['dostępne od', ''],
            'building_type' => ['typ budynku', ''],
            'condition' => ['stan', ''],
        ];

        return collect($labels)
            ->filter(fn ($label, $key) => isset($details[$key]))
            ->map(fn ($label, $key) => "- {$label[0]}: ".trim("{$details[$key]} {$label[1]}"))
            ->values()
            ->all();
    }

    private function range(?int $min, ?int $max, string $unit): string
    {
        if ($min === null && $max === null) {
            return 'bez limitu';
        }

        return trim(($min !== null ? "od {$min}" : '').' '.($max !== null ? "do {$max}" : '').' '.$unit);
    }

    private function value(mixed $value, string $unit = ''): string
    {
        return $value === null || $value === '' ? 'brak danych' : trim("{$value} {$unit}");
    }
}
