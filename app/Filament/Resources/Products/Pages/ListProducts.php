<?php

namespace App\Filament\Resources\Products\Pages;

use App\Filament\Resources\Products\ProductResource;
use App\Filament\Support\Format;
use App\Jobs\CheckProductJob;
use App\Models\Product;
use App\Services\Products\ProductTracker;
use Filament\Actions\Action;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ListRecords;
use Filament\Support\Icons\Heroicon;
use InvalidArgumentException;

class ListProducts extends ListRecords
{
    protected static string $resource = ProductResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Action::make('add')
                ->label('Dodaj produkt')
                ->icon(Heroicon::OutlinedPlus)
                ->modalHeading('Nowy produkt do śledzenia')
                ->modalDescription('Wklej link do strony produktu w sklepie. Od razu sprawdzę cenę i nazwę, potem będę sprawdzać co kilka godzin.')
                ->modalSubmitActionLabel('Dodaj i sprawdź cenę')
                ->modalWidth('lg')
                ->schema([
                    TextInput::make('url')
                        ->label('Link do produktu')
                        ->placeholder('https://sklep.pl/produkt-...')
                        ->url()
                        ->required()
                        ->maxLength(2000),
                    TextInput::make('target_price')
                        ->label('Cena docelowa (opcjonalnie)')
                        ->helperText('Dam znać, gdy cena spadnie do tej kwoty.')
                        ->numeric()
                        ->minValue(0)
                        ->suffix('zł'),
                    Textarea::make('note')
                        ->label('Notatka (opcjonalnie)')
                        ->placeholder('np. tylko kolor Chocolate Brown, z bazą do auta')
                        ->rows(2),
                ])
                ->action(function (array $data, ProductTracker $tracker, Action $action) {
                    try {
                        $product = $tracker->add($data['url'], array_filter([
                            'target_price' => $data['target_price'] ?? null,
                            'note' => $data['note'] ?? null,
                        ], fn ($value) => $value !== null && $value !== ''));
                    } catch (InvalidArgumentException $exception) {
                        Notification::make()->danger()->title('Nie dodałem produktu')->body($exception->getMessage())->send();
                        $action->halt();

                        return;
                    }

                    $offer = $product->offers->first();

                    if ($product->best_price !== null) {
                        Notification::make()->success()->title('Dodane: '.$product->name)->body('Cena: '.Format::pln($product->best_price).'.')->send();
                    } else {
                        Notification::make()->warning()->title('Dodane, ale bez ceny')->body($offer?->last_error ?? 'Spróbuję ponownie przy następnym sprawdzeniu.')->send();
                    }

                    $this->redirect(ProductResource::getUrl('view', ['record' => $product]));
                }),
            Action::make('check_all')
                ->label('Sprawdź wszystkie')
                ->icon(Heroicon::OutlinedArrowPath)
                ->color('gray')
                ->action(function () {
                    $products = Product::where('is_active', true)->get();
                    $products->each(fn (Product $product) => CheckProductJob::dispatch($product));
                    Notification::make()->info()->title("Sprawdzam {$products->count()} produktów w tle")->body('Zmiany przyjdą jako powiadomienia.')->send();
                }),
        ];
    }
}
