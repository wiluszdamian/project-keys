<?php

namespace App\Filament\Resources\Searches\Tables;

use App\Filament\Resources\Listings\ListingResource;
use App\Jobs\RunSearchJob;
use App\Models\Search;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Notifications\Notification;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Columns\ToggleColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Number;

class SearchesTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query) => $query->withCount('listings')->with('latestScrapeRun'))
            ->defaultSort('id')
            ->columns([
                TextColumn::make('name')
                    ->label('Nazwa')
                    ->searchable()
                    ->description(fn (Search $record) => $record->city.($record->districts ? ' · '.implode(', ', $record->districts) : '')),
                TextColumn::make('deal_type')
                    ->label('Rodzaj')
                    ->badge(),
                TextColumn::make('price_max')
                    ->label('Cena')
                    ->state(fn (Search $record) => self::range($record->price_min, $record->price_max, 'zł')),
                TextColumn::make('rooms_min')
                    ->label('Pokoje')
                    ->state(fn (Search $record) => self::range($record->rooms_min, $record->rooms_max)),
                TextColumn::make('portals')
                    ->label('Portale')
                    ->badge(),
                TextColumn::make('min_score_to_notify')
                    ->label('Próg AI')
                    ->suffix('/10'),
                TextColumn::make('listings_count')
                    ->label('Oferty')
                    ->url(fn (Search $record) => ListingResource::getUrl('index', ['filters' => ['search' => ['value' => $record->id]]])),
                TextColumn::make('last_run_at')
                    ->label('Ostatni przebieg')
                    ->since()
                    ->description(fn (Search $record) => $record->latestScrapeRun?->status?->getLabel())
                    ->placeholder('jeszcze nie'),
                ToggleColumn::make('is_active')
                    ->label('Aktywne'),
            ])
            ->recordActions([
                Action::make('run')
                    ->label('Uruchom teraz')
                    ->icon(Heroicon::OutlinedPlay)
                    ->action(function (Search $record) {
                        RunSearchJob::dispatch($record);
                        Notification::make()->success()->title('Przebieg dodany do kolejki')->body('Wynik pojawi się w Przebiegach za minutę lub dwie.')->send();
                    }),
                ActionGroup::make([
                    EditAction::make(),
                    DeleteAction::make(),
                ]),
            ]);
    }

    private static function range(?int $min, ?int $max, ?string $unit = null): string
    {
        $format = fn (int $value) => Number::format($value, locale: 'pl').($unit ? " {$unit}" : '');

        return match (true) {
            $min !== null && $max !== null => 'od '.$format($min).' do '.$format($max),
            $min !== null => 'od '.$format($min),
            $max !== null => 'do '.$format($max),
            default => 'bez limitu',
        };
    }
}
