<?php

namespace App\Filament\Resources\Listings\Pages;

use App\Enums\ListingStatus;
use App\Filament\Resources\Listings\ListingResource;
use App\Models\Listing;
use App\Services\DecisionExamples;
use Filament\Resources\Pages\ListRecords;
use Filament\Schemas\Components\Tabs\Tab;
use Illuminate\Database\Eloquent\Builder;

class ListListings extends ListRecords
{
    protected static string $resource = ListingResource::class;

    public function getTabs(): array
    {
        return [
            'all' => Tab::make('Wszystkie'),
            // Okazje: tańsze niż transakcje lub rynek albo po dużej obniżce (DealFinder, deals:refresh co godzinę).
            'deals' => Tab::make('Okazje')
                ->icon('heroicon-o-fire')
                ->badge(fn () => Listing::query()->whereNotNull('deal_at')->whereNull('duplicate_of_id')->whereHas('searches')->count() ?: null)
                ->badgeColor('success')
                ->modifyQueryUsing(fn (Builder $query) => $query->whereNotNull('deal_at')->reorder('deal_at', 'desc')),
            // Oferty, którymi się zajmujesz: ulubione, kontakt, oglądanie.
            'mine' => Tab::make('Moje')
                ->icon('heroicon-o-star')
                ->modifyQueryUsing(fn (Builder $query) => $query->whereIn('status', array_map(fn (ListingStatus $status) => $status->value, DecisionExamples::LIKED))),
        ];
    }
}
