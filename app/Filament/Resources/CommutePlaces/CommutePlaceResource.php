<?php

namespace App\Filament\Resources\CommutePlaces;

use App\Filament\Resources\CommutePlaces\Pages\ManageCommutePlaces;
use App\Models\CommutePlace;
use BackedEnum;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Fieldset;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Columns\ToggleColumn;
use Filament\Tables\Table;

/**
 * Miejsca, do których liczymy dojazd z ofert (np. praca). Zmiana przelicza dojazdy w tle.
 */
class CommutePlaceResource extends Resource
{
    protected static ?string $model = CommutePlace::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedMapPin;

    protected static ?string $modelLabel = 'miejsce dojazdu';

    protected static ?string $pluralModelLabel = 'miejsca dojazdu';

    protected static ?string $navigationLabel = 'Miejsca dojazdu';

    protected static ?int $navigationSort = 6;

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            TextInput::make('name')
                ->label('Nazwa')
                ->placeholder('np. Praca (ja)')
                ->required()
                ->maxLength(100),
            TextInput::make('address')
                ->label('Adres')
                ->placeholder('np. ul. Rejtana 36, 35-310 Rzeszów')
                ->helperText('Współrzędne znajdę w OpenStreetMap. Możesz też wpisać je niżej sam.')
                ->required()
                ->maxLength(255),
            TextInput::make('max_minutes')
                ->label('Akceptowalny dojazd')
                ->helperText('AI potraktuje dłuższy dojazd jako minus. Puste = bez limitu.')
                ->integer()
                ->minValue(1)
                ->maxValue(240)
                ->suffix('min'),
            Toggle::make('is_active')
                ->label('Liczy dojazd')
                ->default(true)
                ->inline(false),
            Fieldset::make('Współrzędne (opcjonalnie)')
                ->columns(2)
                ->schema([
                    TextInput::make('lat')->label('Szerokość')->numeric()->placeholder('np. 50.0231'),
                    TextInput::make('lng')->label('Długość')->numeric()->placeholder('np. 22.0127'),
                ]),
        ])->columns(2);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('name')->label('Nazwa')->weight('bold'),
                TextColumn::make('address')->label('Adres')->wrap(),
                TextColumn::make('lat')
                    ->label('Położenie')
                    ->state(fn (CommutePlace $record) => $record->lat !== null ? sprintf('%.5f, %.5f', $record->lat, $record->lng) : 'nie znaleziono adresu')
                    ->color(fn (CommutePlace $record) => $record->lat !== null ? null : 'danger')
                    ->url(fn (CommutePlace $record) => $record->lat !== null ? "https://www.openstreetmap.org/?mlat={$record->lat}&mlon={$record->lng}#map=17/{$record->lat}/{$record->lng}" : null, shouldOpenInNewTab: true),
                TextColumn::make('max_minutes')->label('Akceptowalnie')->suffix(' min')->placeholder('bez limitu'),
                TextColumn::make('commutes_count')->label('Policzone dojazdy')->counts('commutes'),
                ToggleColumn::make('is_active')->label('Aktywne'),
            ])
            ->recordActions([
                EditAction::make(),
                DeleteAction::make(),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ManageCommutePlaces::route('/'),
        ];
    }
}
