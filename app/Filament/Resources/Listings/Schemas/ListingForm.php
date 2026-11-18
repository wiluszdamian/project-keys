<?php

namespace App\Filament\Resources\Listings\Schemas;

use App\Enums\ListingStatus;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Schemas\Schema;

/**
 * Oferty przychodzą z portali. W panelu zmieniasz tylko swój status i notatkę.
 */
class ListingForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Select::make('status')
                    ->label('Status')
                    ->options(ListingStatus::class)
                    ->placeholder('Brak')
                    ->helperText('Odrzucona oferta nie da już powiadomień, np. o obniżce ceny.')
                    ->native(false),
                Textarea::make('note')
                    ->label('Notatka')
                    ->placeholder('np. dzwoniłem, oglądanie w sobotę 12:00')
                    ->rows(4)
                    ->columnSpanFull(),
            ]);
    }
}
