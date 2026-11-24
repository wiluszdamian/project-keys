<?php

namespace App\Filament\Resources\ProductShops;

use App\Filament\Resources\ProductShops\Pages\ManageProductShops;
use App\Jobs\SyncProductShopJob;
use App\Models\ProductShop;
use App\Services\Products\ProductUrl;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Columns\ToggleColumn;
use Filament\Tables\Table;
use Illuminate\Support\Str;

/**
 * Sklepy do darmowego szukania produktów: monitor cen czyta ich mapy stron (sitemap).
 */
class ProductShopResource extends Resource
{
    protected static ?string $model = ProductShop::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedBuildingStorefront;

    protected static ?string $modelLabel = 'sklep';

    protected static ?string $pluralModelLabel = 'sklepy';

    protected static ?string $navigationLabel = 'Sklepy';

    protected static string|\UnitEnum|null $navigationGroup = 'Zakupy';

    protected static ?int $navigationSort = 2;

    public const STATUSES = [
        'ok' => 'działa',
        'empty' => 'pusta mapa strony',
        'blocked' => 'blokuje roboty',
        'error' => 'błąd',
    ];

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            TextInput::make('domain')
                ->label('Sklep (adres)')
                ->placeholder('np. bobowozki.com.pl')
                ->required()
                ->maxLength(255)
                ->dehydrateStateUsing(fn (string $state) => ProductUrl::shop(str_contains($state, '://') ? $state : "https://{$state}"))
                ->unique(ignoreRecord: true),
            TextInput::make('name')->label('Nazwa')->maxLength(100),
            TextInput::make('sitemap_url')
                ->label('Mapa strony (opcjonalnie)')
                ->helperText('Puste = adres z robots.txt sklepu albo /sitemap.xml.')
                ->url()
                ->maxLength(500)
                ->columnSpanFull(),
            Toggle::make('is_active')->label('Szukaj w tym sklepie')->default(true)->inline(false),
        ])->columns(2);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('domain')
            ->columns([
                TextColumn::make('domain')
                    ->label('Sklep')
                    ->weight('bold')
                    ->searchable()
                    ->url(fn (ProductShop $record) => "https://{$record->domain}", shouldOpenInNewTab: true)
                    ->description(fn (ProductShop $record) => $record->name),
                TextColumn::make('status')
                    ->label('Stan')
                    ->badge()
                    ->formatStateUsing(fn (?string $state) => self::STATUSES[$state] ?? $state)
                    ->color(fn (?string $state) => match ($state) {
                        'ok' => 'success',
                        'empty' => 'warning',
                        'blocked', 'error' => 'danger',
                        default => 'gray',
                    })
                    ->placeholder('jeszcze nie sprawdzony')
                    ->tooltip(fn (ProductShop $record) => $record->last_error),
                TextColumn::make('urls_count')
                    ->label('Stron w mapie')
                    ->numeric(locale: 'pl')
                    ->sortable(),
                TextColumn::make('source')
                    ->label('Skąd')
                    ->badge()
                    ->formatStateUsing(fn (string $state) => match ($state) {
                        'ai' => 'AI',
                        'default' => 'startowy',
                        default => 'dodany',
                    })
                    ->color(fn (string $state) => $state === 'ai' ? 'info' : 'gray'),
                TextColumn::make('synced_at')
                    ->label('Mapa pobrana')
                    ->since()
                    ->placeholder('jeszcze nie')
                    ->description(fn (ProductShop $record) => $record->last_error ? Str::limit($record->last_error, 60) : null),
                ToggleColumn::make('is_active')->label('Szukaj'),
            ])
            ->recordActions([
                ActionGroup::make([
                    Action::make('sync')
                        ->label('Pobierz mapę teraz')
                        ->icon(Heroicon::OutlinedArrowPath)
                        ->action(function (ProductShop $record) {
                            SyncProductShopJob::dispatch($record);
                            Notification::make()->info()->title("Pobieram mapę strony {$record->domain}")->body('Duże sklepy: do kilku minut. Odśwież stronę.')->send();
                        }),
                    EditAction::make(),
                    DeleteAction::make(),
                ]),
            ])
            ->emptyStateHeading('Brak sklepów')
            ->emptyStateDescription('Dodaj sklepy, w których monitor cen ma szukać produktów. AI też proponuje sklepy przy szukaniu.');
    }

    public static function getPages(): array
    {
        return [
            'index' => ManageProductShops::route('/'),
        ];
    }
}
