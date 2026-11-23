<?php

namespace App\Filament\Support;

use App\Models\Listing;
use App\Support\Portals;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Columns\ViewColumn;

/**
 * Oferta jako jedna karta w tabeli na telefonie i tablecie. Od xl (1280 px) są zwykłe kolumny,
 * bo węższy ekran z menu bocznym nie mieści ich bez przewijania.
 * Wymaga w zapytaniu withMax('analyses', 'score').
 */
class ListingCard
{
    public const BREAKPOINT = 'xl';

    public static function column(): ViewColumn
    {
        return ViewColumn::make('card')
            ->label('Oferta')
            ->view('filament.listings.card')
            ->hiddenFrom(self::BREAKPOINT);
    }

    /**
     * Skąd jest oferta: portal i portale tej samej oferty (duplikaty), każdy jako etykieta.
     * Nazwa 'source', nie 'portal': dawna kolumna 'portal' była ukryta i panel pamięta to w sesji.
     * Wymaga wczytanych duplicates.
     */
    public static function sourceColumn(): TextColumn
    {
        return TextColumn::make('source')
            ->label('Źródło')
            ->state(fn (Listing $record) => Portals::sources($record))
            ->badge()
            ->formatStateUsing(fn (string $state) => Portals::short($state))
            ->color(fn (string $state) => Portals::color($state))
            ->tooltip(fn (Listing $record) => collect(Portals::sources($record))->map(Portals::label(...))->implode(', '))
            ->listWithLineBreaks()
            ->extraAttributes(['class' => 'pk-col-source'])
            ->visibleFrom(self::BREAKPOINT);
    }
}
