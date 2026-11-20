<?php

namespace App\Filament\Support;

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
}
