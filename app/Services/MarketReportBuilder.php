<?php

namespace App\Services;

use App\Enums\DealType;
use App\Enums\ListingStatus;
use App\Models\Listing;
use App\Models\MarketReport;
use App\Models\RcnTransaction;
use App\Models\Search;
use App\Services\Rcn\TransactionComparator;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

/**
 * Liczby do tygodniowego raportu rynku z naszej bazy: oferty widziane w danym tygodniu.
 * Porównanie z poprzednim zapisanym raportem tego samego typu.
 */
class MarketReportBuilder
{
    public const MIN_DISTRICT_SAMPLE = 3;

    /**
     * @return array<string, mixed>
     */
    public function build(DealType $dealType, CarbonInterface $from, CarbonInterface $to): array
    {
        $previous = MarketReport::where('deal_type', $dealType)
            ->where('period_end', '<', $to)
            ->latest('period_end')
            ->first()?->data;

        $totals = $this->summary($this->seen($dealType, $from, $to), $from);
        $totals['median_price_change'] = $this->change($totals['median_price'], $previous['totals']['median_price'] ?? null);
        $totals['median_price_per_m2_change'] = $this->change($totals['median_price_per_m2'], $previous['totals']['median_price_per_m2'] ?? null);

        $previousSearches = collect($previous['searches'] ?? [])->keyBy('id');

        $searches = Search::active()
            ->where('deal_type', $dealType)
            ->orderBy('id')
            ->get()
            ->map(function (Search $search) use ($dealType, $from, $to, $previousSearches) {
                $summary = $this->summary(
                    $this->seen($dealType, $from, $to)->whereHas('searches', fn (Builder $query) => $query->whereKey($search->id)),
                    $from,
                );
                $before = $previousSearches->get($search->id);

                return ['id' => $search->id, 'name' => $search->name] + $summary + [
                    'median_price_change' => $this->change($summary['median_price'], $before['median_price'] ?? null),
                    'median_price_per_m2_change' => $this->change($summary['median_price_per_m2'], $before['median_price_per_m2'] ?? null),
                ];
            })
            ->values()
            ->all();

        return [
            'totals' => $totals,
            'searches' => $searches,
            'districts' => $this->districts($dealType, $from, $to),
            'rooms' => $this->rooms($dealType, $from, $to),
            'price_drops' => $this->priceDrops($dealType, $from, $to),
            'best_new' => $this->bestNew($dealType, $from, $to),
            'has_previous' => $previous !== null,
            'transactions' => $dealType === DealType::Sale ? $this->transactions($from, $to) : null,
            'time_on_market' => TimeOnMarket::weekly($dealType, $from, $to),
        ];
    }

    /**
     * Oferty sprzedaży z tygodnia na tle cen transakcyjnych z RCN i mediany transakcji z ostatnich 3 miesięcy.
     *
     * @return array<string, mixed>|null
     */
    private function transactions(CarbonInterface $from, CarbonInterface $to): ?array
    {
        $comparisons = $this->seen(DealType::Sale, $from, $to)
            ->whereNotNull('transaction_comparison')
            ->pluck('transaction_comparison')
            ->filter(fn (array $comparison) => isset($comparison['diff_percent']));

        $byMarket = RcnTransaction::query()
            ->where('date', '>=', $to->copy()->subMonths(3)->toDateString())
            ->whereNotNull('market')
            ->groupBy('market')
            ->select('market')
            ->selectRaw('count(*) as count')
            ->selectRaw('percentile_cont(0.5) within group (order by price_per_m2) as median')
            ->get()
            ->mapWithKeys(fn ($row) => [$row->market => ['count' => (int) $row->count, 'median_price_per_m2' => $this->round($row->median)]])
            ->all();

        if ($comparisons->isEmpty() && $byMarket === []) {
            return null;
        }

        return [
            'compared' => $comparisons->count(),
            'above' => $comparisons->where('status', TransactionComparator::ABOVE)->count(),
            'market' => $comparisons->where('status', TransactionComparator::MARKET)->count(),
            'below' => $comparisons->where('status', TransactionComparator::BELOW)->count(),
            'median_diff_percent' => $comparisons->isEmpty() ? null : round((float) $comparisons->pluck('diff_percent')->median(), 1),
            'last_3_months' => $byMarket,
        ];
    }

    /**
     * Oferty widziane w tygodniu (od $from do $to), bez duplikatów z innych portali.
     */
    private function seen(DealType $dealType, CarbonInterface $from, CarbonInterface $to): Builder
    {
        return Listing::query()
            ->where('deal_type', $dealType)
            ->whereNull('duplicate_of_id')
            ->where('last_seen_at', '>=', $from)
            ->where('first_seen_at', '<=', $to);
    }

    /**
     * @return array{active: int, new: int, median_price: int|null, median_price_per_m2: int|null}
     */
    private function summary(Builder $query, CarbonInterface $from): array
    {
        $row = $query->toBase()
            ->selectRaw('count(*) as active')
            ->selectRaw('count(*) filter (where first_seen_at >= ?) as new', [$from])
            ->selectRaw('percentile_cont(0.5) within group (order by price) as median_price')
            ->selectRaw('percentile_cont(0.5) within group (order by price_per_m2) as median_ppm2')
            ->first();

        return [
            'active' => (int) $row->active,
            'new' => (int) $row->new,
            'median_price' => $this->round($row->median_price),
            'median_price_per_m2' => $this->round($row->median_ppm2),
        ];
    }

    /**
     * Dzielnice z co najmniej kilkoma ofertami, od największej liczby ofert.
     *
     * @return list<array{name: string, count: int, median_price_per_m2: int|null}>
     */
    private function districts(DealType $dealType, CarbonInterface $from, CarbonInterface $to): array
    {
        return $this->seen($dealType, $from, $to)
            ->toBase()
            ->whereNotNull('district')
            ->whereNotNull('price_per_m2')
            ->select('district')
            ->selectRaw('count(*) as count')
            ->selectRaw('percentile_cont(0.5) within group (order by price_per_m2) as median_ppm2')
            ->groupBy('district')
            ->havingRaw('count(*) >= ?', [self::MIN_DISTRICT_SAMPLE])
            ->orderByDesc('count')
            ->limit(10)
            ->get()
            ->map(fn (object $row) => ['name' => $row->district, 'count' => (int) $row->count, 'median_price_per_m2' => $this->round($row->median_ppm2)])
            ->all();
    }

    /**
     * @return list<array{rooms: string, count: int, median_price: int|null}>
     */
    private function rooms(DealType $dealType, CarbonInterface $from, CarbonInterface $to): array
    {
        return $this->seen($dealType, $from, $to)
            ->toBase()
            ->whereNotNull('rooms')
            ->selectRaw('least(rooms, 4) as bucket')
            ->selectRaw('count(*) as count')
            ->selectRaw('percentile_cont(0.5) within group (order by price) as median_price')
            ->groupByRaw('least(rooms, 4)')
            ->orderBy('bucket')
            ->get()
            ->map(fn (object $row) => ['rooms' => (int) $row->bucket >= 4 ? '4+' : (string) $row->bucket, 'count' => (int) $row->count, 'median_price' => $this->round($row->median_price)])
            ->all();
    }

    /**
     * Obniżki z tygodnia: dla każdej oferty pierwsza cena w tygodniu kontra ostatnia.
     * Cena skacząca w górę i w dół liczy się raz i tylko, gdy na koniec jest niższa.
     *
     * @return array{count: int, median_percent: float|null, top: list<array<string, mixed>>}
     */
    private function priceDrops(DealType $dealType, CarbonInterface $from, CarbonInterface $to): array
    {
        $drops = DB::table('listing_price_changes as changes')
            ->join('listings', 'listings.id', '=', 'changes.listing_id')
            ->where('listings.deal_type', $dealType->value)
            ->whereNull('listings.duplicate_of_id')
            ->whereBetween('changes.changed_at', [$from, $to])
            ->orderBy('changes.changed_at')
            ->orderBy('changes.id')
            ->get(['listings.id', 'listings.title', 'listings.url', 'changes.old_price', 'changes.new_price'])
            ->groupBy('id')
            ->map(function ($changes) {
                $first = $changes->first();
                $old = (int) $first->old_price;
                $new = (int) $changes->last()->new_price;

                return $old > 0 && $new > 0 && $new < $old ? [
                    'id' => $first->id,
                    'title' => $first->title,
                    'url' => $first->url,
                    'old_price' => $old,
                    'new_price' => $new,
                    'percent' => round(($old - $new) * 100 / $old, 1),
                ] : null;
            })
            ->filter();

        return [
            'count' => $drops->count(),
            'median_percent' => $drops->isEmpty() ? null : round((float) $drops->pluck('percent')->median(), 1),
            'top' => $drops->sortByDesc('percent')->take(5)->values()->all(),
        ];
    }

    /**
     * Najlepiej ocenione nowe oferty tygodnia (bez odrzuconych).
     *
     * @return list<array<string, mixed>>
     */
    private function bestNew(DealType $dealType, CarbonInterface $from, CarbonInterface $to): array
    {
        return $this->seen($dealType, $from, $to)
            ->where('first_seen_at', '>=', $from)
            ->where(fn (Builder $query) => $query->whereNull('status')->orWhere('status', '!=', ListingStatus::Rejected->value))
            ->whereHas('analyses')
            ->withMax('analyses', 'score')
            ->orderByDesc('analyses_max_score')
            ->orderBy('price')
            ->limit(5)
            ->get()
            ->map(fn (Listing $listing) => [
                'id' => $listing->id,
                'title' => $listing->title,
                'url' => $listing->url,
                'score' => (int) $listing->analyses_max_score,
                'price' => $listing->price,
                'area' => $listing->area !== null ? (float) $listing->area : null,
                'rooms' => $listing->rooms,
                'district' => $listing->district ?? $listing->city,
            ])
            ->all();
    }

    private function change(?int $now, ?int $before): ?float
    {
        return $now !== null && $before ? round(($now - $before) / $before * 100, 1) : null;
    }

    private function round(mixed $value): ?int
    {
        return $value === null ? null : (int) round((float) $value);
    }
}
