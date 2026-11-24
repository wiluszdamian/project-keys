<?php

namespace App\Filament\Resources\Products\Pages;

use App\Filament\Resources\Products\ProductResource;
use App\Filament\Resources\Products\Widgets\ProductPriceChart;
use App\Filament\Support\Format;
use App\Jobs\FindProductOffersJob;
use App\Jobs\ProductAdviceJob;
use App\Models\Product;
use App\Services\Products\ProductFinder;
use App\Services\Products\ProductReader;
use App\Services\Products\ProductTracker;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ViewRecord;
use Filament\Support\Icons\Heroicon;
use Illuminate\Contracts\Support\Htmlable;
use InvalidArgumentException;

class ViewProduct extends ViewRecord
{
    protected static string $resource = ProductResource::class;

    public function getTitle(): string|Htmlable
    {
        return $this->getRecord()->name;
    }

    public function getSubheading(): ?string
    {
        /** @var Product $product */
        $product = $this->getRecord();

        return collect([$product->brand, $product->gtin ? "EAN {$product->gtin}" : null])->filter()->implode(' · ') ?: null;
    }

    protected function getFooterWidgets(): array
    {
        return [ProductPriceChart::class];
    }

    public function getWidgetData(): array
    {
        return ['record' => $this->getRecord()];
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('open')
                ->label('Otwórz w sklepie')
                ->icon(Heroicon::OutlinedArrowTopRightOnSquare)
                ->url(fn (Product $record) => ($record->bestOffer ?? $record->primaryOffer())?->url, shouldOpenInNewTab: true),
            Action::make('check')
                ->label('Sprawdź teraz')
                ->icon(Heroicon::OutlinedArrowPath)
                ->color('gray')
                ->action(function (Product $record, ProductTracker $tracker) {
                    $events = $tracker->check($record);
                    $record->refresh();
                    $errors = $record->offers->where('is_active', true)->whereNotNull('last_error')->count();

                    Notification::make()
                        ->status($errors ? 'warning' : 'success')
                        ->title('Cena: '.(Format::pln($record->best_price) ?? 'niedostępny'))
                        ->body(collect($events)->pluck('text')->push($errors ? "Błędy odczytu: {$errors} (szczegóły w tabeli sklepów)." : null)->filter()->implode(' ') ?: 'Bez zmian.')
                        ->send();
                }),
            ActionGroup::make([
                Action::make('find')
                    ->label('Szukaj w innych sklepach')
                    ->icon(Heroicon::OutlinedMagnifyingGlass)
                    ->disabled(fn () => ! ProductFinder::isConfigured())
                    ->tooltip(fn () => ProductFinder::isConfigured() ? null : 'Dodaj klucz Brave Search API w Ustawieniach → Produkty.')
                    ->action(function (Product $record) {
                        FindProductOffersJob::dispatch($record, report: true);
                        Notification::make()->info()->title('Szukam w innych sklepach')->body('Sprawdzam wyniki wyszukiwarki, AI porównuje produkty. Zwykle 1 do 3 minut, dam znać w dzwoneczku.')->send();
                    }),
                Action::make('add_offer')
                    ->label('Dodaj link z innego sklepu')
                    ->icon(Heroicon::OutlinedLink)
                    ->modalWidth('lg')
                    ->schema([
                        TextInput::make('url')->label('Link do tego samego produktu w innym sklepie')->url()->required()->maxLength(2000),
                    ])
                    ->action(function (Product $record, array $data, ProductTracker $tracker, Action $action) {
                        $before = $tracker->snapshot($record);

                        try {
                            $offer = $tracker->addOffer($record, $data['url']);
                        } catch (InvalidArgumentException $exception) {
                            Notification::make()->danger()->title('Nie dodałem linku')->body($exception->getMessage())->send();
                            $action->halt();

                            return;
                        }

                        $tracker->refresh($record, $before);
                        Notification::make()
                            ->status($offer->last_error ? 'warning' : 'success')
                            ->title("Dodane: {$offer->shop}")
                            ->body($offer->last_error ?? 'Cena: '.Format::pln($offer->price).'.')
                            ->send();
                    }),
                Action::make('advice')
                    ->label(fn (Product $record) => $record->ai_advice ? 'Oceń cenę ponownie (AI)' : 'Oceń cenę (AI)')
                    ->icon(Heroicon::OutlinedSparkles)
                    ->visible(fn () => ProductReader::aiEnabled())
                    ->disabled(fn (Product $record) => $record->best_price === null)
                    ->action(function (Product $record) {
                        ProductAdviceJob::dispatch($record);
                        Notification::make()->info()->title('AI ocenia cenę')->body('Zwykle do minuty. Odśwież stronę.')->send();
                    }),
                EditAction::make()->label('Ustawienia produktu'),
                DeleteAction::make(),
            ])
                ->label('Więcej')
                ->icon(Heroicon::OutlinedEllipsisVertical)
                ->color('gray')
                ->button(),
        ];
    }
}
