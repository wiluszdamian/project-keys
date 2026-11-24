<?php

namespace App\Filament\Resources\Products;

use App\Filament\Resources\Products\Pages\EditProduct;
use App\Filament\Resources\Products\Pages\ListProducts;
use App\Filament\Resources\Products\Pages\ViewProduct;
use App\Filament\Resources\Products\RelationManagers\OffersRelationManager;
use App\Filament\Support\Format;
use App\Models\Product;
use App\Services\Products\ProductAdvisor;
use BackedEnum;
use Filament\Actions\EditAction;
use Filament\Actions\ViewAction;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Infolists\Components\ImageEntry;
use Filament\Infolists\Components\TextEntry;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\ImageColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Columns\ToggleColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\HtmlString;

/**
 * Monitor cen: produkty do kupienia, ich ceny w sklepach i ocena AI.
 */
class ProductResource extends Resource
{
    protected static ?string $model = Product::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedShoppingBag;

    protected static ?string $modelLabel = 'produkt';

    protected static ?string $pluralModelLabel = 'produkty';

    protected static ?string $navigationLabel = 'Monitor cen';

    protected static string|\UnitEnum|null $navigationGroup = 'Zakupy';

    protected static ?int $navigationSort = 1;

    protected static ?string $recordTitleAttribute = 'name';

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            TextInput::make('name')
                ->label('Nazwa')
                ->required()
                ->maxLength(255)
                ->columnSpanFull(),
            TextInput::make('target_price')
                ->label('Cena docelowa')
                ->helperText('Powiadomienie, gdy najlepsza cena spadnie do tej kwoty.')
                ->numeric()
                ->minValue(0)
                ->suffix('zł'),
            TextInput::make('check_every_hours')
                ->label('Sprawdzaj co')
                ->helperText('Puste = '.config('products.check_every_hours').' h (Ustawienia → Produkty).')
                ->integer()
                ->minValue(1)
                ->maxValue(168)
                ->suffix('h'),
            Toggle::make('notify_drops')->label('Powiadamiaj o obniżkach')->inline(false),
            TextInput::make('min_drop_percent')
                ->label('Tylko obniżki od')
                ->helperText('0 = każda obniżka.')
                ->integer()
                ->minValue(0)
                ->maxValue(90)
                ->suffix('%'),
            Toggle::make('notify_rises')->label('Powiadamiaj o podwyżkach')->inline(false),
            Toggle::make('notify_stock')->label('Powiadamiaj o dostępności')->helperText('Znowu dostępny albo wyprzedany.')->inline(false),
            Toggle::make('is_active')->label('Śledź cenę')->inline(false),
            Textarea::make('note')
                ->label('Notatka')
                ->helperText('AI bierze ją pod uwagę przy ocenie ceny i porównaniu ofert (np. "tylko kolor Chocolate Brown, z bazą").')
                ->rows(3)
                ->columnSpanFull(),
        ])->columns(2);
    }

    public static function infolist(Schema $schema): Schema
    {
        return $schema
            ->columns(['default' => 1, 'lg' => 3])
            ->components([
                Section::make('Cena')
                    ->columnSpan(['default' => 1, 'lg' => 2])
                    ->columns(['default' => 2, 'sm' => 4])
                    ->schema([
                        ImageEntry::make('image_url')
                            ->hiddenLabel()
                            ->imageHeight(140)
                            ->extraImgAttributes(['style' => 'object-fit: contain', 'loading' => 'lazy'])
                            ->visible(fn (Product $record) => filled($record->image_url))
                            ->columnSpan(['default' => 2, 'sm' => 1]),
                        Grid::make(['default' => 2, 'sm' => 3])
                            ->columnSpan(['default' => 2, 'sm' => 3])
                            ->schema([
                                TextEntry::make('best_price')
                                    ->label('Najlepsza cena')
                                    ->formatStateUsing(fn ($state) => Format::pln($state))
                                    ->placeholder('niedostępny')
                                    ->size('lg')
                                    ->weight('bold')
                                    ->color(fn (Product $record) => $record->targetReached() ? 'success' : null)
                                    ->helperText(fn (Product $record) => $record->bestOffer?->shop),
                                TextEntry::make('change')
                                    ->label('Od początku')
                                    ->state(fn (Product $record) => self::change($record))
                                    ->color(fn (Product $record) => self::changeColor($record))
                                    ->placeholder('bez zmian')
                                    ->helperText(fn (Product $record) => $record->initial_price ? 'było '.Format::pln($record->initial_price) : null),
                                TextEntry::make('target_price')
                                    ->label('Cena docelowa')
                                    ->formatStateUsing(fn ($state) => Format::pln($state))
                                    ->placeholder('nie ustawiona')
                                    ->badge(fn (Product $record) => $record->targetReached())
                                    ->color(fn (Product $record) => $record->targetReached() ? 'success' : null),
                                TextEntry::make('lowest_price')
                                    ->label('Najniższa')
                                    ->formatStateUsing(fn ($state) => Format::pln($state))
                                    ->helperText(fn (Product $record) => $record->lowest_price_at?->format('d.m.Y')),
                                TextEntry::make('highest_price')
                                    ->label('Najwyższa')
                                    ->formatStateUsing(fn ($state) => Format::pln($state)),
                                TextEntry::make('last_checked_at')
                                    ->label('Sprawdzone')
                                    ->since()
                                    ->placeholder('jeszcze nie')
                                    ->helperText(fn (Product $record) => $record->is_active ? 'co '.$record->everyHours().' h' : 'śledzenie wyłączone'),
                            ]),
                        TextEntry::make('note')
                            ->label('Notatka')
                            ->visible(fn (Product $record) => filled($record->note))
                            ->columnSpanFull(),
                    ]),
                Section::make('Ocena AI')
                    ->columnSpan(1)
                    ->schema([
                        TextEntry::make('ai_verdict')
                            ->hiddenLabel()
                            ->state(fn (Product $record) => $record->ai_advice ? (ProductAdvisor::VERDICTS[$record->ai_advice['verdict']] ?? $record->ai_advice['verdict']).' · '.$record->ai_advice['score'].'/10' : null)
                            ->badge()
                            ->size('lg')
                            ->color(fn (Product $record) => self::verdictColor($record))
                            ->placeholder('AI jeszcze nie oceniło ceny.'),
                        TextEntry::make('ai_summary')
                            ->hiddenLabel()
                            ->state(fn (Product $record) => $record->ai_advice['summary'] ?? null)
                            ->visible(fn (Product $record) => filled($record->ai_advice['summary'] ?? null)),
                        TextEntry::make('ai_reasons')
                            ->label('Dlaczego')
                            ->state(fn (Product $record) => self::list($record->ai_advice['reasons'] ?? []))
                            ->html()
                            ->visible(fn (Product $record) => filled($record->ai_advice['reasons'] ?? null)),
                        TextEntry::make('ai_tips')
                            ->label('Wskazówki')
                            ->state(fn (Product $record) => self::list($record->ai_advice['tips'] ?? []))
                            ->html()
                            ->visible(fn (Product $record) => filled($record->ai_advice['tips'] ?? null)),
                        TextEntry::make('ai_advice_at')
                            ->label('Ocenione')
                            ->since()
                            ->helperText(fn (Product $record) => isset($record->ai_advice['price']) ? 'przy cenie '.Format::pln($record->ai_advice['price']) : null)
                            ->visible(fn (Product $record) => $record->ai_advice_at !== null),
                    ]),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('updated_at', 'desc')
            ->poll('60s')
            ->recordUrl(fn (Product $record) => self::getUrl('view', ['record' => $record]))
            ->modifyQueryUsing(fn (Builder $query) => $query->with(['bestOffer', 'offers:id,product_id,is_active']))
            ->columns([
                ImageColumn::make('image_url')
                    ->label('')
                    ->imageHeight(48)
                    ->extraImgAttributes(['style' => 'object-fit: contain', 'loading' => 'lazy']),
                TextColumn::make('name')
                    ->label('Produkt')
                    ->searchable()
                    ->weight('bold')
                    ->wrap()
                    ->lineClamp(2)
                    ->description(fn (Product $record) => collect([
                        $record->bestOffer?->shop,
                        ($count = $record->offers->where('is_active', true)->count()) > 1 ? "{$count} sklepy" : null,
                        $record->is_active ? null : 'nie śledzę',
                    ])->filter()->implode(' · ')),
                TextColumn::make('best_price')
                    ->label('Cena')
                    ->sortable()
                    ->formatStateUsing(fn ($state) => Format::pln($state))
                    ->placeholder('niedostępny')
                    ->weight('bold')
                    ->description(fn (Product $record) => self::change($record))
                    ->color(fn (Product $record) => $record->targetReached() ? 'success' : null),
                TextColumn::make('lowest_price')
                    ->label('Najniższa')
                    ->sortable()
                    ->formatStateUsing(fn ($state) => Format::pln($state))
                    ->description(fn (Product $record) => $record->lowest_price_at?->format('d.m.Y'))
                    ->toggleable(),
                TextColumn::make('target_price')
                    ->label('Cel')
                    ->sortable()
                    ->formatStateUsing(fn ($state) => Format::pln($state))
                    ->placeholder('-')
                    ->badge(fn (Product $record) => $record->targetReached())
                    ->color(fn (Product $record) => $record->targetReached() ? 'success' : null),
                TextColumn::make('ai_advice')
                    ->label('AI')
                    ->state(fn (Product $record) => $record->ai_advice ? (ProductAdvisor::VERDICTS[$record->ai_advice['verdict']] ?? '').' '.$record->ai_advice['score'].'/10' : null)
                    ->badge()
                    ->color(fn (Product $record) => self::verdictColor($record))
                    ->tooltip(fn (Product $record) => $record->ai_advice['summary'] ?? null)
                    ->placeholder('-'),
                TextColumn::make('last_checked_at')
                    ->label('Sprawdzone')
                    ->since()
                    ->sortable()
                    ->placeholder('jeszcze nie')
                    ->toggleable(),
                ToggleColumn::make('is_active')
                    ->label('Śledź'),
            ])
            ->filters([
                TernaryFilter::make('is_active')->label('Śledzone'),
                Filter::make('target')
                    ->label('Cena docelowa osiągnięta')
                    ->query(fn (Builder $query) => $query->whereNotNull('target_price')->whereColumn('best_price', '<=', 'target_price')),
            ])
            ->recordActions([
                ViewAction::make(),
                EditAction::make(),
            ])
            ->emptyStateHeading('Nie śledzisz jeszcze żadnego produktu')
            ->emptyStateDescription('Kliknij „Dodaj produkt” i wklej link do strony produktu w sklepie.');
    }

    public static function getRelations(): array
    {
        return [
            OffersRelationManager::class,
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListProducts::route('/'),
            'view' => ViewProduct::route('/{record}'),
            'edit' => EditProduct::route('/{record}/edit'),
        ];
    }

    public static function change(Product $record): ?string
    {
        $percent = $record->changePercent();

        if ($percent === null || abs($percent) < 0.05) {
            return null;
        }

        $diff = $record->best_price - $record->initial_price;

        return ($diff < 0 ? '−' : '+').Format::pln(abs($diff)).' ('.($percent < 0 ? '−' : '+').number_format(abs($percent), 1, ',', '').'%)';
    }

    public static function changeColor(Product $record): ?string
    {
        $percent = $record->changePercent();

        return match (true) {
            $percent === null || abs($percent) < 0.05 => null,
            $percent < 0 => 'success',
            default => 'danger',
        };
    }

    public static function verdictColor(Product $record): string
    {
        return match ($record->ai_advice['verdict'] ?? null) {
            'buy' => 'success',
            'good' => 'info',
            'wait' => 'warning',
            default => 'gray',
        };
    }

    /**
     * @param  list<string>  $items
     */
    private static function list(array $items): HtmlString
    {
        return new HtmlString('<ul style="list-style: disc; padding-left: 1.25rem">'.collect($items)->map(fn (string $item) => '<li>'.e($item).'</li>')->implode('').'</ul>');
    }
}
