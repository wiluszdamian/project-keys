<?php

namespace App\Services;

use App\Enums\DealType;
use App\Models\Listing;

/**
 * Mediana ceny za m2 z naszej bazy (ostatnie 60 dni). Model nie ocenia cen "z głowy".
 */
class MarketStats
{
    public const DAYS = 60;

    public const MIN_SAMPLE = 10;

    /**
     * @return array{scope: string, median_price_per_m2: int|null, median_total_cost_per_m2: int|null, sample_size: int, enough_data: bool}
     */
    public function forListing(Listing $listing): array
    {
        // Najpierw dzielnica. Gdy ofert jest za mało, całe miasto.
        if ($listing->district) {
            $district = $this->stats($listing->city, $listing->deal_type, $listing->district);

            if ($district['sample_size'] >= self::MIN_SAMPLE) {
                return ['scope' => "dzielnica {$listing->district}"] + $district;
            }
        }

        return ['scope' => "miasto {$listing->city}"] + $this->stats($listing->city, $listing->deal_type);
    }

    private function stats(?string $city, DealType $dealType, ?string $district = null): array
    {
        $base = Listing::query()
            ->where('city', $city)
            ->where('deal_type', $dealType)
            ->whereNull('duplicate_of_id')
            ->where('last_seen_at', '>=', now()->subDays(self::DAYS))
            ->whereNotNull('price_per_m2')
            ->when($district, fn ($query) => $query->where('district', $district));

        $row = (clone $base)
            ->selectRaw('count(*) as sample_size')
            ->selectRaw('percentile_cont(0.5) within group (order by price_per_m2) as median_price')
            ->first();

        // Dla wynajmu: (najem + czynsz) / m2, tylko oferty z podanym czynszem.
        $totalMedian = $dealType === DealType::Rent
            ? (clone $base)->whereNotNull('rent_extra')->where('area', '>', 0)
                ->selectRaw('percentile_cont(0.5) within group (order by (price + rent_extra) / area) as median_total')
                ->value('median_total')
            : null;

        $sample = (int) $row->sample_size;

        return [
            'median_price_per_m2' => $row->median_price !== null ? (int) round($row->median_price) : null,
            'median_total_cost_per_m2' => $totalMedian !== null ? (int) round($totalMedian) : null,
            'sample_size' => $sample,
            'enough_data' => $sample >= self::MIN_SAMPLE,
        ];
    }
}
