<?php

namespace App\Services;

use App\Enums\DealType;
use App\Enums\ListingStatus;
use App\Models\Listing;
use App\Models\MarketReport;
use App\Models\Product;
use App\Models\ProductOffer;
use App\Models\ProductPrice;
use App\Models\RcnTransaction;
use App\Models\Search;
use App\Services\Rcn\TransactionComparator;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Liczby do tygodniowego raportu rynku z naszej bazy: oferty widziane w danym tygodniu.
 * Porównanie z poprzednim zapisanym raportem tego samego typu.
 */
class MarketReportBuilder
{
    public const MIN_DISTRICT_SAMPLE = 3;

    /**
     * Ile tygodni wstecz w trendzie.
     */
    public const TREND_WEEKS = 8;

    /**
     * Statusy ofert, które osoba obserwuje (sekcja "Twoja lista").
     */
    private const WATCHED = [ListingStatus::Favorite, ListingStatus::Contacted, ListingStatus::Viewing];

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
        $totals['median_total_change'] = $this->change($totals['median_total'], $previous['totals']['median_total'] ?? null);
        $timing = TimeOnMarket::weekly($dealType, $from, $to);

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
                    'median_total_change' => $this->change($summary['median_total'], $before['median_total'] ?? null),
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
            'time_on_market' => $timing,
            'supply' => [
                'new' => $totals['new'],
                'gone' => $timing['gone'],
                'net' => $totals['new'] - $timing['gone'],
                'active_change' => $this->change($totals['active'], $previous['totals']['active'] ?? null),
            ],
            'trend' => $this->trend($dealType, $to),
            'your_list' => $this->yourList($dealType, $from, $to),
            'deals' => $this->deals($dealType, $from, $to),
            'products' => $this->products($from, $to),
        ];
    }

    /**
     * Te same liczby tydzień po tygodniu (z ofert w bazie, nie z zapisanych raportów), od najstarszego.
     *
     * @return list<array{from: string, to: string, label: string, active: int, new: int, median_price: ?int, median_price_per_m2: ?int, median_total: ?int}>
     */
    private function trend(DealType $dealType, CarbonInterface $to): array
    {
        $weeks = [];

        for ($i = self::TREND_WEEKS - 1; $i >= 0; $i--) {
            $end = $to->copy()->subDays(7 * $i);
            $start = $end->copy()->subDays(7);
            $summary = $this->summary($this->seen($dealType, $start, $end), $start);

            if ($summary['active'] > 0) {
                $weeks[] = ['from' => $start->toDateString(), 'to' => $end->toDateString(), 'label' => $end->format('d.m')] + $summary;
            }
        }

        return $weeks;
    }

    /**
     * Oferty ze statusem ulubiona, kontakt i oglądanie: zmiany ceny w tygodniu, zdjęte, nadchodzące oglądania.
     *
     * @return list<array<string, mixed>>
     */
    private function yourList(DealType $dealType, CarbonInterface $from, CarbonInterface $to): array
    {
        return Listing::query()
            ->where('deal_type', $dealType)
            ->whereIn('status', array_map(fn (ListingStatus $status) => $status->value, self::WATCHED))
            ->with([
                'priceChanges' => fn ($query) => $query->whereBetween('changed_at', [$from, $to]),
                'duplicates:id,duplicate_of_id,portal,first_seen_at,published_at,inactive_at',
            ])
            ->get()
            ->map(function (Listing $listing) use ($from, $to) {
                $changes = $listing->priceChanges;
                $old = $changes->isNotEmpty() ? (int) $changes->first()->old_price : null;
                $gone = $listing->inactive_at !== null && $listing->duplicates->every(fn (Listing $duplicate) => $duplicate->inactive_at !== null);

                return [
                    'id' => $listing->id,
                    'title' => $listing->title,
                    'url' => $listing->url,
                    'status' => $listing->status->value,
                    'price' => $listing->price,
                    'old_price' => $old !== null && $old !== (int) $listing->price ? $old : null,
                    'gone' => $gone,
                    'gone_this_week' => $gone && $listing->inactive_at->between($from, $to),
                    'viewing_at' => $listing->viewing_at?->isAfter($to->copy()->subDay()) ? $listing->viewing_at->toIso8601String() : null,
                    'long' => TimeOnMarket::longBadge($listing),
                ];
            })
            ->sortBy(fn (array $row) => [$row['viewing_at'] === null, $row['gone'], $row['status']])
            ->values()
            ->all();
    }

    /**
     * Oferty, które w tym tygodniu stały się okazjami (taniej niż transakcje lub rynek, duża obniżka).
     *
     * @return array{count: int, top: list<array<string, mixed>>}
     */
    private function deals(DealType $dealType, CarbonInterface $from, CarbonInterface $to): array
    {
        $query = Listing::query()
            ->where('deal_type', $dealType)
            ->whereNull('duplicate_of_id')
            ->whereNull('inactive_at')
            ->whereHas('searches')
            ->whereBetween('deal_at', [$from, $to])
            ->where(fn (Builder $query) => $query->whereNull('status')->orWhere('status', '!=', ListingStatus::Rejected->value));

        return [
            'count' => (clone $query)->count(),
            'top' => $query->withMax('analyses', 'score')
                ->orderByDesc('analyses_max_score')
                ->orderBy('price')
                ->limit(3)
                ->get()
                ->map(fn (Listing $listing) => [
                    'id' => $listing->id,
                    'title' => $listing->title,
                    'url' => $listing->url,
                    'price' => $listing->price,
                    'score' => $listing->analyses_max_score !== null ? (int) $listing->analyses_max_score : null,
                    'reasons' => DealFinder::summary($listing->deal_reasons),
                ])
                ->all(),
        ];
    }

    /**
     * Monitor cen: najlepsza cena na początku i na końcu tygodnia dla śledzonych produktów.
     *
     * @return list<array<string, mixed>>
     */
    private function products(CarbonInterface $from, CarbonInterface $to): array
    {
        return Product::query()
            ->where('is_active', true)
            ->with(['offers' => fn ($query) => $query->where('is_active', true), 'offers.prices' => fn ($query) => $query->orderBy('checked_at')])
            ->orderBy('name')
            ->get()
            ->map(function (Product $product) use ($from) {
                // Najlepsza cena na początku tygodnia: ostatni zapis każdej oferty sprzed tygodnia.
                $before = $product->offers
                    ->map(fn (ProductOffer $offer) => $offer->prices->filter(fn (ProductPrice $price) => $price->checked_at->lte($from))->last())
                    ->filter(fn (?ProductPrice $price) => $price !== null && $price->available !== false && $price->price !== null)
                    ->min('price');

                return [
                    'id' => $product->id,
                    'name' => $product->name,
                    'best_price' => $product->best_price,
                    'price_week_ago' => $before !== null ? (float) $before : null,
                    'change_percent' => $before && $product->best_price !== null ? round(($product->best_price - $before) / $before * 100, 1) : null,
                    'lowest_price' => $product->lowest_price,
                    'target_price' => $product->target_price,
                    'target_reached' => $product->targetReached(),
                    'shop' => $product->offers->firstWhere('id', $product->best_offer_id)?->shop,
                    'verdict' => $product->ai_advice['verdict'] ?? null,
                    'score' => $product->ai_advice['score'] ?? null,
                ];
            })
            ->values()
            ->all();
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
        // Tylko oferty, które pasują do aktywnego wyszukiwania. Odłączone przez zmianę filtrów zostają w bazie, ale nie w raporcie.
        return Listing::query()
            ->where('deal_type', $dealType)
            ->whereNull('duplicate_of_id')
            ->where('last_seen_at', '>=', $from)
            ->where('first_seen_at', '<=', $to)
            ->whereHas('searches', fn (Builder $query) => $query->where('searches.deal_type', $dealType)->where('searches.is_active', true));
    }

    /**
     * median_total: najem razem z czynszem administracyjnym (tylko oferty, które go podają), total_known: ile ich jest.
     *
     * @return array{active: int, new: int, median_price: int|null, median_price_per_m2: int|null, median_total: int|null, total_known: int}
     */
    private function summary(Builder $query, CarbonInterface $from): array
    {
        $row = $query->toBase()
            ->selectRaw('count(*) as active')
            ->selectRaw('count(*) filter (where first_seen_at >= ?) as new', [$from])
            ->selectRaw('percentile_cont(0.5) within group (order by price) as median_price')
            ->selectRaw('percentile_cont(0.5) within group (order by price_per_m2) as median_ppm2')
            ->selectRaw('percentile_cont(0.5) within group (order by price + rent_extra) filter (where rent_extra is not null) as median_total')
            ->selectRaw('count(*) filter (where rent_extra is not null) as total_known')
            ->first();

        return [
            'active' => (int) $row->active,
            'new' => (int) $row->new,
            'median_price' => $this->round($row->median_price),
            'median_price_per_m2' => $this->round($row->median_ppm2),
            'median_total' => $this->round($row->median_total),
            'total_known' => (int) $row->total_known,
        ];
    }

    /**
     * Dzielnice z co najmniej kilkoma ofertami, od największej liczby ofert.
     * Ta sama dzielnica zapisana różnie ("Os. Projektant", "Projektant", literówka) liczy się raz, pod najczęstszą nazwą.
     *
     * @return list<array{name: string, count: int, median_price_per_m2: int|null}>
     */
    private function districts(DealType $dealType, CarbonInterface $from, CarbonInterface $to): array
    {
        $rows = $this->seen($dealType, $from, $to)
            ->whereNotNull('district')
            ->whereNotNull('price_per_m2')
            ->get(['district', 'price_per_m2']);

        $keys = $rows->pluck('district')->countBy(fn (string $district) => self::districtKey($district))->sortDesc();
        $canonical = [];

        // Literówki: klucz różniący się o 1-2 znaki od częstszego trafia do niego.
        foreach ($keys->keys() as $key) {
            $canonical[$key] = collect($canonical)->keys()->first(fn (string $known) => mb_strlen($key) > 6 && levenshtein($key, $known) <= 2) ?? $key;
        }

        return $rows
            ->groupBy(fn (Listing $listing) => $canonical[self::districtKey($listing->district)])
            ->filter(fn (Collection $group) => $group->count() >= self::MIN_DISTRICT_SAMPLE)
            ->map(fn (Collection $group, string $key) => [
                // Najczęstszy zapis bez literówki, potem wielką literą, potem najkrótszy ("Projektant" zamiast "Os. Projektant").
                'name' => $group->pluck('district')->map(fn (string $name) => trim($name))->countBy()
                    ->filter(fn (int $count, string $name) => self::districtKey($name) === $key)
                    ->map(fn (int $count, string $name) => ['name' => $name, 'count' => $count, 'lower' => mb_strtolower($name) === $name, 'length' => mb_strlen($name)])
                    ->sortBy([['count', 'desc'], ['lower', 'asc'], ['length', 'asc']])
                    ->first()['name'] ?? trim($group->first()->district),
                'count' => $group->count(),
                'median_price_per_m2' => $this->round($group->pluck('price_per_m2')->map(fn ($value) => (float) $value)->median()),
            ])
            ->sortByDesc('count')
            ->take(10)
            ->values()
            ->all();
    }

    /**
     * Klucz do łączenia nazw dzielnic: małe litery bez polskich znaków, bez "osiedle", "gen." i znaków przestankowych.
     */
    public static function districtKey(string $district): string
    {
        $key = ' '.strtolower(Str::ascii($district)).' ';
        $key = preg_replace('/[^a-z0-9]+/', ' ', $key);
        $key = preg_replace('/\s(osiedle|os|osk|gen|generala|sw|swietego|ul|al)\s/', ' ', $key);
        $key = preg_replace('/\s(osiedle|os|gen|generala)\s/', ' ', $key);

        return str_replace(['1000 lecia', 'tysiaclecia'], 'tysiaclecia', Str::squish($key));
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
            ->whereExists(fn ($query) => $query->select(DB::raw(1))->from('search_listing')->join('searches', 'searches.id', '=', 'search_listing.search_id')
                ->whereColumn('search_listing.listing_id', 'listings.id')->where('searches.is_active', true))
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
