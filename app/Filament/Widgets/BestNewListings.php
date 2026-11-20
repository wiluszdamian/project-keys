<?php

namespace App\Filament\Widgets;

use App\Enums\ListingStatus;
use App\Filament\Support\Format;
use App\Filament\Support\ListingCard;
use App\Filament\Support\ListingFilters;
use App\Models\Listing;
use Filament\Tables\Columns\ImageColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Filament\Widgets\TableWidget;
use Illuminate\Database\Eloquent\Builder;

class BestNewListings extends TableWidget
{
    protected static ?int $sort = 2;

    protected int|string|array $columnSpan = 'full';

    protected static ?string $heading = 'Najlepsze oferty z ostatnich 3 dni';

    public function table(Table $table): Table
    {
        return $table
            ->query(fn () => Listing::query()
                ->withMax('analyses', 'score')
                ->withMax('analyses', 'monthly_cost')
                ->whereNull('duplicate_of_id')
                ->where(fn (Builder $query) => $query->whereNull('status')->orWhere('status', '!=', ListingStatus::Rejected->value))
                ->where('first_seen_at', '>=', now()->subDays(3))
                ->whereHas('analyses'))
            ->defaultSort('analyses_max_score', 'desc')
            ->paginated([10])
            ->recordUrl(fn (Listing $record) => route('filament.admin.resources.listings.view', $record))
            ->filters([
                ListingFilters::aboveThreshold(),
            ])
            ->persistFiltersInSession()
            ->columns([
                ListingCard::column(),
                ImageColumn::make('image_url')
                    ->label('')
                    ->imageWidth(80)
                    ->imageHeight(54)
                    ->extraImgAttributes(['class' => 'rounded-md object-cover', 'loading' => 'lazy'])
                    ->visibleFrom(ListingCard::BREAKPOINT),
                TextColumn::make('title')
                    ->label('Oferta')
                    ->wrap()
                    ->lineClamp(2)
                    ->weight('medium')
                    ->extraAttributes(['class' => 'pk-col-title'])
                    ->description(fn (Listing $record) => collect([$record->district ?: $record->city, $record->street])->filter()->unique()->implode(' · '))
                    ->visibleFrom(ListingCard::BREAKPOINT),
                TextColumn::make('deal_type')
                    ->label('Rodzaj')
                    ->badge()
                    ->visibleFrom('2xl'),
                TextColumn::make('analyses_max_score')
                    ->label('Ocena')
                    ->badge()
                    ->color(fn ($state) => Format::scoreColor($state))
                    ->formatStateUsing(fn ($state) => "{$state}/10")
                    ->sortable()
                    ->visibleFrom(ListingCard::BREAKPOINT),
                TextColumn::make('price')
                    ->label('Cena')
                    ->formatStateUsing(fn ($state) => Format::pln($state))
                    ->description(fn (Listing $record) => $record->rent_extra ? '+ '.Format::pln($record->rent_extra).' czynsz' : null)
                    ->visibleFrom(ListingCard::BREAKPOINT),
                TextColumn::make('analyses_max_monthly_cost')
                    ->label('Koszt / mies.')
                    ->formatStateUsing(fn ($state) => Format::pln($state))
                    ->placeholder('-')
                    ->visibleFrom('2xl'),
                TextColumn::make('area')
                    ->label('Metraż')
                    ->formatStateUsing(fn ($state) => Format::area($state))
                    ->description(fn (Listing $record) => $record->rooms ? $record->rooms.' pok.' : null)
                    ->visibleFrom(ListingCard::BREAKPOINT),
                TextColumn::make('first_seen_at')
                    ->label('Znaleziona')
                    ->since()
                    ->visibleFrom(ListingCard::BREAKPOINT),
            ]);
    }
}
