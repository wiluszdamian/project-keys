<?php

namespace App\Services;

use App\Enums\DealType;
use App\Models\Listing;
use App\Services\Rcn\TransactionComparator;
use Illuminate\Support\Number;

/**
 * Okazje: oferta wyraźnie tańsza niż transakcje w okolicy, niż mediana ofert za m² albo po dużej obniżce.
 * Progi w config/deals.php (i w Ustawieniach). Wynik zapisuje w listings.deal_reasons i deal_at.
 */
class DealFinder
{
    public const BELOW_TRANSACTIONS = 'below_transactions';

    public const PRICE_DROP = 'price_drop';

    public const BELOW_MARKET = 'below_market';

    /**
     * @return list<array{type: string, text: string}>
     */
    public function reasons(Listing $listing): array
    {
        if ($listing->price === null || $listing->duplicate_of_id !== null) {
            return [];
        }

        // Tania, ale słabo dopasowana oferta to nie okazja. Bez oceny (AI wyłączone albo jeszcze nie oceniona) liczymy.
        $score = $listing->analyses()->max('score');

        if ($score !== null && $score < (int) config('deals.min_score')) {
            return [];
        }

        return array_values(array_filter([
            $this->belowTransactions($listing),
            $this->priceDrop($listing),
            $this->belowMarket($listing),
        ]));
    }

    /**
     * Zapisuje powody. Zwraca true, gdy oferta właśnie stała się okazją (wcześniej nią nie była).
     */
    public function refresh(Listing $listing): bool
    {
        $reasons = $this->reasons($listing);
        $wasDeal = $listing->deal_at !== null;

        $listing->forceFill([
            'deal_reasons' => $reasons ?: null,
            'deal_at' => $reasons ? ($listing->deal_at ?? now()) : null,
        ])->save();

        return $reasons !== [] && ! $wasDeal;
    }

    /**
     * Krótko do listy i wiadomości: "14% taniej niż transakcje · cena −6%".
     */
    public static function summary(?array $reasons): ?string
    {
        return $reasons ? collect($reasons)->pluck('text')->implode(' · ') : null;
    }

    private function belowTransactions(Listing $listing): ?array
    {
        $comparison = $listing->transaction_comparison;
        $threshold = (int) config('deals.below_transactions_percent');

        if ($listing->deal_type !== DealType::Sale
            || ($comparison['status'] ?? null) !== TransactionComparator::BELOW
            || ! empty($comparison['approximate'])
            || ($comparison['diff_percent'] ?? 0) > -$threshold) {
            return null;
        }

        return [
            'type' => self::BELOW_TRANSACTIONS,
            'text' => $this->percent(-$comparison['diff_percent']).' taniej niż transakcje w okolicy',
        ];
    }

    private function priceDrop(Listing $listing): ?array
    {
        $highest = (int) $listing->priceChanges()->max('old_price');

        if ($highest <= 0 || $listing->price >= $highest) {
            return null;
        }

        $drop = ($highest - $listing->price) / $highest * 100;

        if ($drop < (int) config('deals.price_drop_percent')) {
            return null;
        }

        return [
            'type' => self::PRICE_DROP,
            'text' => 'cena spadła o '.$this->percent($drop).' (z '.number_format($highest, 0, ',', ' ').' zł)',
        ];
    }

    private function belowMarket(Listing $listing): ?array
    {
        $area = (float) $listing->area;

        if ($area <= 0) {
            return null;
        }

        // Przy wynajmie najem z czynszem, gdy czynsz jest znany.
        $withFee = $listing->deal_type === DealType::Rent && $listing->rent_extra !== null;
        $value = ($listing->price + ($withFee ? $listing->rent_extra : 0)) / $area;
        $stats = $this->similarMedian($listing, $withFee);

        if ($stats === null) {
            return null;
        }

        [$median, $scope] = $stats;

        $below = ($median - $value) / $median * 100;

        // Bardzo duża różnica to raczej błąd albo oszustwo (RiskDetector), nie okazja.
        $threshold = (int) config($listing->deal_type === DealType::Rent ? 'deals.below_market_percent_rent' : 'deals.below_market_percent');

        if ($below < $threshold || $below >= 40) {
            return null;
        }

        return [
            'type' => self::BELOW_MARKET,
            'text' => $this->percent($below).' taniej za m² niż podobne oferty ('.$scope.')',
        ];
    }

    /**
     * Mediana za m² ofert o podobnym metrażu (±20%) z ostatnich 30 dni: dzielnica, a przy małej próbie miasto.
     * Duże mieszkania są tańsze za m² niż kawalerki, więc porównanie z całym rynkiem zawyżało okazje.
     *
     * @return array{0: float, 1: string}|null
     */
    private function similarMedian(Listing $listing, bool $withFee): ?array
    {
        $area = (float) $listing->area;
        $base = Listing::query()
            ->where('city', $listing->city)
            ->where('deal_type', $listing->deal_type)
            ->whereNull('duplicate_of_id')
            ->whereKeyNot($listing->id)
            ->where('last_seen_at', '>=', now()->subDays(MarketStats::DAYS))
            ->whereNotNull('price')
            ->whereBetween('area', [$area * 0.8, $area * 1.2])
            ->when($withFee, fn ($query) => $query->whereNotNull('rent_extra'));
        $expression = $withFee ? '(price + rent_extra) / area' : 'price / area';
        $median = fn ($query) => $query->selectRaw("count(*) as n, percentile_cont(0.5) within group (order by {$expression}) as median")->first();

        foreach (array_filter([$listing->district ? 'district' : null, 'city']) as $level) {
            $row = $median($level === 'district' ? (clone $base)->where('district', $listing->district) : clone $base);

            if ((int) $row->n >= MarketStats::MIN_SAMPLE && $row->median > 0) {
                $scope = $level === 'district' ? "dzielnica {$listing->district}" : "miasto {$listing->city}";

                return [(float) $row->median, $scope.', podobny metraż'];
            }
        }

        return null;
    }

    private function percent(float $value): string
    {
        return Number::format($value, maxPrecision: 0, locale: 'pl').'%';
    }
}
