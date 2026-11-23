<?php

namespace App\Filament\Support;

use App\Enums\DealType;
use App\Models\Listing;
use App\Models\ListingCommute;
use Illuminate\Support\Number;

class Format
{
    public static function pln(int|float|string|null $value): ?string
    {
        return $value === null || $value === '' ? null : Number::format((float) $value, locale: 'pl').' zł';
    }

    public static function area(int|float|string|null $value): ?string
    {
        return $value === null || $value === '' ? null : Number::format((float) $value, maxPrecision: 2, locale: 'pl').' m²';
    }

    public static function scoreColor(?int $score): string
    {
        return match (true) {
            $score === null => 'gray',
            $score >= 8 => 'success',
            $score >= 6 => 'warning',
            default => 'danger',
        };
    }

    /**
     * Dojazdy oferty, np. ["Praca (ja): 🚗 8 min · 🚲 14 min"]. Wymaga załadowanych commutes.place.
     *
     * @return list<string>
     */
    public static function commutes(Listing $listing): array
    {
        return $listing->commutes
            ->filter(fn (ListingCommute $commute) => $commute->place?->is_active)
            ->groupBy('commute_place_id')
            ->map(function ($commutes) {
                $byMode = $commutes->keyBy('mode');

                return $commutes->first()->place->name.': '.collect(['car' => '🚗', 'bike' => '🚲'])
                    ->filter(fn ($icon, $mode) => $byMode->has($mode))
                    ->map(fn ($icon, $mode) => "{$icon} {$byMode[$mode]->minutes} min")
                    ->implode(' · ');
            })
            ->values()
            ->all();
    }

    /**
     * Czynsz pod ceną najmu: "+ 510 zł czynsz", "+ 450 zł czynsz (z opisu)" albo "czynsz nieznany".
     * Przy sprzedaży null. Wymaga wczytanych duplicates i analyses.
     */
    public static function adminFee(Listing $listing): ?string
    {
        if ($listing->deal_type !== DealType::Rent) {
            return null;
        }

        [$fee, $source] = $listing->adminFee();

        return match ($source) {
            null => 'czynsz nieznany',
            'description' => '+ '.self::pln($fee).' czynsz (z opisu)',
            default => '+ '.self::pln($fee).' czynsz',
        };
    }
}
