<?php

namespace App\Services;

use App\Ai\Agents\MarketReportAgent;
use App\Enums\DealType;
use App\Enums\ListingStatus;
use App\Models\Search;
use Illuminate\Support\Str;
use Throwable;

/**
 * Podsumowanie raportu rynku przez AI: kilka zdań i punkty do zrobienia. Bez AI raport idzie bez podsumowania.
 */
class MarketReportSummarizer
{
    public function __construct(private ListingScorer $scorer) {}

    /**
     * @param  array<string, mixed>  $data
     * @return array{text: string, points: list<string>}|null
     */
    public function summarize(DealType $dealType, array $data): ?array
    {
        if (! ListingScorer::isEnabled()) {
            return null;
        }

        $this->scorer->countCall();

        try {
            $answer = MarketReportAgent::make()->prompt(self::prompt($dealType, $data))->toArray();
        } catch (Throwable $exception) {
            $this->scorer->countError();
            report($exception);

            return null;
        }

        $text = Str::limit(trim((string) ($answer['summary'] ?? '')), 1200);

        if ($text === '') {
            return null;
        }

        return [
            'text' => $text,
            'points' => collect($answer['points'] ?? [])->filter(fn ($point) => is_string($point) && trim($point) !== '')->map(fn (string $point) => Str::limit(trim($point), 300))->take(4)->values()->all(),
        ];
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public static function prompt(DealType $dealType, array $data): string
    {
        $rent = $dealType === DealType::Rent;
        $money = fn ($value) => $value === null ? 'brak' : number_format((float) $value, 0, ',', ' ').' zł';
        $change = fn ($value) => $value === null ? '' : ' ('.($value > 0 ? '+' : '').$value.'% do poprzedniego tygodnia)';
        $totals = $data['totals'];
        $supply = $data['supply'] ?? null;

        $lines = [
            'Dzisiaj jest '.now()->format('Y-m-d').'. Raport: '.($rent ? 'wynajem' : 'sprzedaż').'.',
            '',
            '## Preferencje osoby',
            ...(Search::active()->where('deal_type', $dealType)->get()->map(fn (Search $search) => "- {$search->name}".($search->ai_preferences ? ': '.Str::squish($search->ai_preferences) : ''))->all() ?: ['- brak']),
            '',
            '## Ten tydzień',
            "- oferty: {$totals['active']}, nowe: {$totals['new']}".($supply ? ", zniknęły z portali: {$supply['gone']}, bilans: {$supply['net']}" : ''),
            '- mediana ceny: '.$money($totals['median_price']).$change($totals['median_price_change']),
            '- mediana za m2: '.$money($totals['median_price_per_m2']).$change($totals['median_price_per_m2_change']),
            $rent ? '- mediana najmu z czynszem administracyjnym: '.$money($totals['median_total'] ?? null)." (z {$totals['total_known']} ofert, które podają czynsz)" : null,
            ...collect($data['searches'])->map(fn (array $search) => "- wyszukiwanie {$search['name']}: {$search['active']} ofert, {$search['new']} nowych, mediana ".$money($search['median_price']).$change($search['median_price_change']))->all(),
            '- obniżki cen: '.$data['price_drops']['count'].($data['price_drops']['median_percent'] !== null ? ', mediana '.$data['price_drops']['median_percent'].'%' : ''),
            '- okazje: '.($data['deals']['count'] ?? 0),
            isset($data['time_on_market']['median_days']) ? '- zdjęte oferty były na rynku średnio (mediana) '.$data['time_on_market']['median_days'].' dni' : null,
            ($transactions = $data['transactions'] ?? null) ? "- na tle cen transakcyjnych: {$transactions['below']} ofert taniej, {$transactions['market']} w granicach rynku, {$transactions['above']} drożej" : null,
            '',
            '## Trend (tydzień kończący się, oferty, mediana ceny, mediana za m2)',
            ...collect($data['trend'] ?? [])->map(fn (array $week) => "- {$week['label']}: {$week['active']}, ".$money($week['median_price']).', '.$money($week['median_price_per_m2']))->all(),
            '',
            '## Obserwowane oferty osoby',
            ...(collect($data['your_list'] ?? [])->map(fn (array $item) => '- '.ListingStatus::from($item['status'])->getLabel().": {$item['title']}, ".$money($item['price'])
                .($item['old_price'] ? ' (było '.$money($item['old_price']).')' : '')
                .($item['gone'] ? ', zdjęta z portali' : '')
                .($item['viewing_at'] ? ', oglądanie '.substr($item['viewing_at'], 0, 16) : '')
                .($item['long'] ? ", {$item['long']}" : ''))->all() ?: ['- brak']),
        ];

        return implode("\n", array_filter($lines, fn ($line) => $line !== null));
    }
}
