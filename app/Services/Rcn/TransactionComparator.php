<?php

namespace App\Services\Rcn;

use App\Enums\DealType;
use App\Models\Listing;
use App\Models\RcnTransaction;
use Illuminate\Support\Collection;
use Illuminate\Support\Number;

/**
 * Oferta sprzedaży na tle transakcji z RCN w okolicy: podobny metraż, ostatnie miesiące,
 * najpierw ten sam rynek (pierwotny albo wtórny), gdy wiemy, który to.
 */
class TransactionComparator
{
    public const BELOW = 'below';

    public const MARKET = 'market';

    public const ABOVE = 'above';

    public const TOO_FEW = 'too_few';

    private const EARTH_M = 6_371_000;

    /**
     * Wynik do zapisu w listings.transaction_comparison albo null, gdy oferty nie da się porównać.
     *
     * @return array<string, mixed>|null
     */
    public function compare(Listing $listing): ?array
    {
        if ($listing->deal_type !== DealType::Sale || ! $listing->hasCoordinates() || ! $listing->price || ! (float) $listing->area) {
            return null;
        }

        $area = (float) $listing->area;
        $tolerance = config('rcn.area_tolerance_percent') / 100;
        $months = (int) config('rcn.compare_months');
        $min = (int) config('rcn.min_comparables');
        $radii = $this->radii($listing->geo_precision);
        $market = $listing->market();

        $candidates = $this->candidates($listing, $area * (1 - $tolerance), $area * (1 + $tolerance), $months, max($radii));
        $offerPerM2 = (int) round($listing->price / $area);
        $best = collect();

        foreach ($radii as $radius) {
            $near = $candidates->where('distance', '<=', $radius);
            $sameMarket = $market ? $near->where('market', $market) : collect();
            [$used, $usedMarket] = $sameMarket->count() >= $min ? [$sameMarket, $market] : [$near, null];

            if ($used->count() > $best->count()) {
                $best = $used;
            }

            if ($used->count() >= $min) {
                return $this->result($used, $offerPerM2, $radius, $usedMarket, $market, $months)
                    + ['approximate' => $listing->geo_precision === 'city'];
            }
        }

        return [
            'status' => self::TOO_FEW,
            'count' => $best->count(),
            'radius_m' => max($radii),
            'months' => $months,
            'offer_price_per_m2' => $offerPerM2,
            'listing_market' => $market,
        ];
    }

    /**
     * Krótki opis, np. "12% drożej niż mediana transakcji: 9 850 zł/m² (23 transakcje do 800 m, 24 mies., rynek wtórny)".
     */
    public static function describe(?array $comparison): ?string
    {
        if (! $comparison) {
            return null;
        }

        if (($comparison['status'] ?? null) === self::TOO_FEW) {
            return "Za mało transakcji w okolicy do porównania ({$comparison['count']} w {$comparison['months']} mies.).";
        }

        $diff = $comparison['diff_percent'];
        $verdict = match ($comparison['status']) {
            self::ABOVE => Number::format(abs($diff), maxPrecision: 0, locale: 'pl').'% drożej niż',
            self::BELOW => Number::format(abs($diff), maxPrecision: 0, locale: 'pl').'% taniej niż',
            default => 'W granicach rynku ('.($diff > 0 ? '+' : '').Number::format($diff, maxPrecision: 0, locale: 'pl').'%):',
        };

        return (! empty($comparison['approximate']) ? 'Położenie oferty znane tylko z dokładnością do miasta, porównanie z całym miastem. ' : '')
            ."{$verdict} mediana transakcji ".self::money($comparison['median_price_per_m2']).'/m² ('
            .self::details($comparison).').';
    }

    /**
     * Krótko do tabeli, np. "14% taniej niż transakcje", "rynkowo (+3%)". Null, gdy nie ma porównania.
     */
    public static function short(?array $comparison): ?string
    {
        if (! $comparison || ! isset($comparison['diff_percent']) || ($comparison['status'] ?? null) === self::TOO_FEW) {
            return null;
        }

        $diff = round($comparison['diff_percent']);
        $text = match ($comparison['status']) {
            self::ABOVE => abs($diff).'% drożej niż transakcje',
            self::BELOW => abs($diff).'% taniej niż transakcje',
            default => 'rynkowo ('.($diff > 0 ? '+' : '').$diff.'%)',
        };

        return $text.(! empty($comparison['approximate']) ? ' ~' : '');
    }

    public static function details(array $comparison): string
    {
        $radius = $comparison['radius_m'] >= 1000
            ? Number::format($comparison['radius_m'] / 1000, maxPrecision: 1, locale: 'pl').' km'
            : $comparison['radius_m'].' m';

        return implode(', ', array_filter([
            "{$comparison['count']} transakcji do {$radius}",
            "{$comparison['months']} mies.",
            match ($comparison['market'] ?? null) {
                RcnTransaction::PRIMARY => 'rynek pierwotny',
                RcnTransaction::SECONDARY => 'rynek wtórny',
                default => 'oba rynki',
            },
        ]));
    }

    public static function money(int|float|null $value): string
    {
        return number_format((float) $value, 0, ',', ' ').' zł';
    }

    /**
     * @return list<int>
     */
    private function radii(?string $precision): array
    {
        return match ($precision) {
            // Środek miasta: porównanie z całym miastem.
            'city' => [6000],
            'district' => [1500, 3000],
            default => config('rcn.radii_m'),
        };
    }

    /**
     * Transakcje w kwadracie wokół oferty, z odległością w metrach.
     *
     * @return Collection<int, RcnTransaction>
     */
    private function candidates(Listing $listing, float $minArea, float $maxArea, int $months, int $radius): Collection
    {
        $lat = (float) $listing->lat;
        $lng = (float) $listing->lng;
        $dLat = rad2deg($radius / self::EARTH_M);
        $dLng = rad2deg($radius / (self::EARTH_M * cos(deg2rad($lat))));

        return RcnTransaction::query()
            ->whereBetween('date', [now()->subMonths($months)->toDateString(), now()->toDateString()])
            ->whereBetween('area', [$minArea, $maxArea])
            ->whereBetween('lat', [$lat - $dLat, $lat + $dLat])
            ->whereBetween('lng', [$lng - $dLng, $lng + $dLng])
            ->get()
            ->each(fn (RcnTransaction $transaction) => $transaction->setAttribute('distance', $this->distance($lat, $lng, $transaction->lat, $transaction->lng)))
            ->sortBy('distance')
            ->values();
    }

    /**
     * @param  Collection<int, RcnTransaction>  $used
     */
    private function result(Collection $used, int $offerPerM2, int $radius, ?string $usedMarket, ?string $listingMarket, int $months): array
    {
        $prices = $used->pluck('price_per_m2')->sort()->values();
        $median = (int) round($prices->median());
        $diff = round(($offerPerM2 - $median) / $median * 100, 1);
        $tolerance = (float) config('rcn.market_tolerance_percent');

        return [
            'status' => match (true) {
                $diff > $tolerance => self::ABOVE,
                $diff < -$tolerance => self::BELOW,
                default => self::MARKET,
            },
            'median_price_per_m2' => $median,
            'p25_price_per_m2' => (int) round($this->percentile($prices, 0.25)),
            'p75_price_per_m2' => (int) round($this->percentile($prices, 0.75)),
            'offer_price_per_m2' => $offerPerM2,
            'diff_percent' => $diff,
            'count' => $used->count(),
            'radius_m' => $radius,
            'months' => $months,
            'market' => $usedMarket,
            'listing_market' => $listingMarket,
            'latest_date' => $used->max(fn (RcnTransaction $transaction) => $transaction->date->toDateString()),
            'nearest' => $used->take(5)->map(fn (RcnTransaction $transaction) => [
                'date' => $transaction->date->toDateString(),
                'area' => $transaction->area,
                'rooms' => $transaction->rooms,
                'floor' => $transaction->floor,
                'price' => $transaction->price,
                'price_per_m2' => $transaction->price_per_m2,
                'market' => $transaction->market,
                'distance_m' => (int) round($transaction->distance),
            ])->values()->all(),
        ];
    }

    private function percentile(Collection $sorted, float $p): float
    {
        $index = ($sorted->count() - 1) * $p;
        $low = (int) floor($index);
        $high = (int) ceil($index);

        return $sorted[$low] + ($sorted[$high] - $sorted[$low]) * ($index - $low);
    }

    private function distance(float $lat1, float $lng1, float $lat2, float $lng2): float
    {
        $dLat = deg2rad($lat2 - $lat1);
        $dLng = deg2rad($lng2 - $lng1);
        $a = sin($dLat / 2) ** 2 + cos(deg2rad($lat1)) * cos(deg2rad($lat2)) * sin($dLng / 2) ** 2;

        return 2 * self::EARTH_M * asin(min(1, sqrt($a)));
    }
}
