<?php

namespace App\Filament\Resources\Products\RelationManagers;

use App\Filament\Support\Format;
use App\Models\Product;
use App\Models\ProductOffer;
use App\Services\Products\ProductTracker;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Actions\DeleteAction;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Columns\ToggleColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;
use InvalidArgumentException;

/**
 * Sklepy z tym produktem: cena, dostępność, skąd link i ostatnie sprawdzenie.
 */
class OffersRelationManager extends RelationManager
{
    protected static string $relationship = 'offers';

    protected static ?string $title = 'Sklepy';

    // Kilka wierszy: od razu z resztą strony, bez doczytywania.
    protected static bool $isLazy = false;

    protected static ?string $modelLabel = 'sklep';

    protected static ?string $pluralModelLabel = 'sklepy';

    public function isReadOnly(): bool
    {
        return false;
    }

    public static function getBadge(Model $ownerRecord, string $pageClass): ?string
    {
        return (string) $ownerRecord->offers()->where('is_active', true)->count();
    }

    public function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn ($query) => $query->orderByDesc('is_active')->orderByRaw('price is null')->orderBy('price'))
            ->columns([
                TextColumn::make('shop')
                    ->label('Sklep')
                    ->weight('bold')
                    ->url(fn (ProductOffer $record) => $record->url, shouldOpenInNewTab: true)
                    ->description(fn (ProductOffer $record) => Str::limit((string) $record->title, 80)),
                TextColumn::make('price')
                    ->label('Cena')
                    ->formatStateUsing(fn ($state) => Format::pln($state))
                    ->placeholder('?')
                    ->weight('bold')
                    ->color(fn (ProductOffer $record) => $record->id === $this->getOwnerRecord()->best_offer_id ? 'success' : null)
                    ->description(fn (ProductOffer $record) => collect([
                        $record->regular_price ? 'przed promocją '.Format::pln($record->regular_price) : null,
                        $record->lowest_30d_price ? 'najniższa z 30 dni '.Format::pln($record->lowest_30d_price) : null,
                        $record->currency !== 'PLN' ? $record->currency : null,
                    ])->filter()->implode(' · ') ?: null),
                IconColumn::make('available')
                    ->label('Dostępny')
                    ->boolean()
                    ->placeholder('?'),
                TextColumn::make('source')
                    ->label('Skąd')
                    ->badge()
                    ->formatStateUsing(fn (ProductOffer $record) => match (true) {
                        $record->is_primary => 'główny',
                        $record->source === ProductOffer::SOURCE_SEARCH => 'znaleziony',
                        default => 'dodany',
                    })
                    ->color(fn (ProductOffer $record) => $record->source === ProductOffer::SOURCE_SEARCH ? 'info' : 'gray')
                    ->tooltip(fn (ProductOffer $record) => $record->match_note),
                TextColumn::make('last_checked_at')
                    ->label('Sprawdzone')
                    ->since()
                    ->placeholder('jeszcze nie')
                    ->description(fn (ProductOffer $record) => $record->last_error
                        ? Str::limit($record->last_error, 80)
                        : ($record->extracted_by ? 'cena z: '.self::method($record->extracted_by) : null))
                    ->color(fn (ProductOffer $record) => $record->last_error ? 'danger' : null)
                    ->tooltip(fn (ProductOffer $record) => $record->last_error),
                ToggleColumn::make('is_active')
                    ->label('Śledź'),
            ])
            ->headerActions([
                Action::make('add')
                    ->label('Dodaj link')
                    ->icon(Heroicon::OutlinedLink)
                    ->schema([
                        TextInput::make('url')->label('Link do tego samego produktu w innym sklepie')->url()->required()->maxLength(2000),
                    ])
                    ->action(function (array $data, ProductTracker $tracker, Action $action) {
                        /** @var Product $product */
                        $product = $this->getOwnerRecord();
                        $before = $tracker->snapshot($product);

                        try {
                            $offer = $tracker->addOffer($product, $data['url']);
                        } catch (InvalidArgumentException $exception) {
                            Notification::make()->danger()->title('Nie dodałem linku')->body($exception->getMessage())->send();
                            $action->halt();

                            return;
                        }

                        $tracker->refresh($product, $before);
                        Notification::make()->status($offer->last_error ? 'warning' : 'success')->title("Dodane: {$offer->shop}")->body($offer->last_error ?? 'Cena: '.Format::pln($offer->price).'.')->send();
                    }),
            ])
            ->recordActions([
                ActionGroup::make([
                    Action::make('check')
                        ->label('Sprawdź teraz')
                        ->icon(Heroicon::OutlinedArrowPath)
                        ->action(function (ProductOffer $record, ProductTracker $tracker) {
                            $product = $this->getOwnerRecord();
                            $before = $tracker->snapshot($product);
                            $tracker->checkOffer($record);
                            $tracker->refresh($product, $before);
                            $record->refresh();
                            Notification::make()->status($record->last_error ? 'warning' : 'success')->title($record->shop)->body($record->last_error ?? 'Cena: '.Format::pln($record->price).'.')->send();
                        }),
                    Action::make('primary')
                        ->label('Ustaw jako główny')
                        ->icon(Heroicon::OutlinedStar)
                        ->visible(fn (ProductOffer $record) => ! $record->is_primary)
                        ->action(function (ProductOffer $record) {
                            $record->product->offers()->update(['is_primary' => false]);
                            $record->forceFill(['is_primary' => true])->save();
                        }),
                    DeleteAction::make()
                        ->after(function (ProductTracker $tracker) {
                            $product = $this->getOwnerRecord();
                            $tracker->refresh($product, $tracker->snapshot($product));
                        }),
                ]),
            ])
            ->paginated(false);
    }

    private static function method(string $method): string
    {
        return match ($method) {
            'json-ld', 'meta', 'microdata' => 'danych strony',
            'shop' => 'układu sklepu',
            'text' => 'treści strony',
            'ai' => 'AI',
            default => $method,
        };
    }
}
