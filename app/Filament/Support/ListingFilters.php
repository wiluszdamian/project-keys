<?php

namespace App\Filament\Support;

use Filament\Tables\Filters\Filter;
use Illuminate\Database\Eloquent\Builder;

class ListingFilters
{
    /**
     * Tylko oferty, które w którymś wyszukiwaniu mają ocenę od progu powiadomień tego wyszukiwania.
     */
    public static function aboveThreshold(): Filter
    {
        return Filter::make('above_threshold')
            ->label('Tylko z oceną od progu wyszukiwania')
            ->toggle()
            ->default()
            ->query(fn (Builder $query) => $query->whereHas('analyses', fn (Builder $query) => $query
                ->join('searches', 'searches.id', '=', 'listing_analyses.search_id')
                ->whereColumn('listing_analyses.score', '>=', 'searches.min_score_to_notify')));
    }
}
