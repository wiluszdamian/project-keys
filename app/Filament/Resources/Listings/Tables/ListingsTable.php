<?php

namespace App\Filament\Resources\Listings\Tables;

use App\Enums\DealType;
use App\Enums\ListingStatus;
use App\Filament\Support\Format;
use App\Filament\Support\ListingCard;
use App\Filament\Support\ListingFilters;
use App\Models\CommutePlace;
use App\Models\Listing;
use App\Services\Rcn\TransactionComparator;
use App\Support\Portals;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Actions\BulkAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\ImageColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;

class ListingsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            // Tylko oferty, które pasują do jakiegoś wyszukiwania. Odłączone (np. po zmianie filtrów) znikają z listy.
            ->modifyQueryUsing(fn (Builder $query) => $query
                ->whereHas('searches')
                ->with('commutes.place')
                ->withMax('analyses', 'score')
                ->withMax('analyses', 'monthly_cost'))
            ->defaultSort('first_seen_at', 'desc')
            ->recordUrl(fn (Listing $record) => route('filament.admin.resources.listings.view', $record))
            ->columns([
                // Telefon: jedna karta z najważniejszymi danymi. Od md zwykłe kolumny.
                ListingCard::column(),
                ImageColumn::make('image_url')
                    ->label('')
                    ->imageWidth(80)
                    ->imageHeight(56)
                    ->extraImgAttributes(['class' => 'rounded-md object-cover', 'loading' => 'lazy'])
                    ->visibleFrom(ListingCard::BREAKPOINT),
                TextColumn::make('title')
                    ->label('Oferta')
                    ->searchable()
                    ->wrap()
                    ->lineClamp(2)
                    ->weight('medium')
                    ->extraAttributes(['class' => 'pk-col-title'])
                    ->description(fn (Listing $record) => collect([$record->district ?: $record->city, $record->street, $record->first_seen_at?->diffForHumans(short: true)])->filter()->unique()->implode(' · '))
                    ->visibleFrom(ListingCard::BREAKPOINT),
                TextColumn::make('deal_type')
                    ->label('Rodzaj')
                    ->badge()
                    ->toggleable(isToggledHiddenByDefault: true)
                    ->visibleFrom(ListingCard::BREAKPOINT),
                TextColumn::make('analyses_max_score')
                    ->label('Ocena')
                    ->badge()
                    ->color(fn ($state) => Format::scoreColor($state))
                    ->formatStateUsing(fn ($state) => "{$state}/10")
                    ->placeholder('-')
                    ->sortable()
                    ->visibleFrom(ListingCard::BREAKPOINT),
                TextColumn::make('price')
                    ->label('Cena')
                    ->formatStateUsing(fn ($state) => Format::pln($state))
                    // Najem: czynsz. Sprzedaż: krótko, jak cena wypada wobec transakcji w okolicy.
                    ->wrap()
                    ->extraAttributes(['class' => 'pk-col-price'])
                    ->description(fn (Listing $record) => $record->rent_extra
                        ? '+ '.Format::pln($record->rent_extra).' czynsz'
                        : TransactionComparator::short($record->transaction_comparison))
                    ->sortable()
                    ->visibleFrom(ListingCard::BREAKPOINT),
                TextColumn::make('analyses_max_monthly_cost')
                    ->label('Koszt / mies.')
                    ->formatStateUsing(fn ($state) => Format::pln($state))
                    ->placeholder('-')
                    ->toggleable()
                    ->visibleFrom('2xl'),
                TextColumn::make('area')
                    ->label('Metraż')
                    ->formatStateUsing(fn ($state) => Format::area($state))
                    ->wrap()
                    ->description(fn (Listing $record) => collect([
                        $record->rooms ? $record->rooms.' pok.' : null,
                        $record->price_per_m2 ? Format::pln($record->price_per_m2).'/m²' : null,
                    ])->filter()->implode(' · ') ?: null)
                    ->sortable()
                    ->visibleFrom(ListingCard::BREAKPOINT),
                TextColumn::make('rooms')
                    ->label('Pokoje')
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true)
                    ->visibleFrom(ListingCard::BREAKPOINT),
                TextColumn::make('commutes')
                    ->label('Dojazd')
                    // Krótko, żeby kolumna się mieściła: "Praca 🚗 24 · 🚲 17 min".
                    ->state(fn (Listing $record) => array_map(fn (string $line) => str_replace([': ', ' min ·'], [' ', ' ·'], $line), Format::commutes($record)))
                    ->listWithLineBreaks()
                    ->size('xs')
                    ->description(fn (Listing $record) => in_array($record->geo_precision, ['district', 'city'], true) ? 'położenie przybliżone' : null)
                    ->placeholder('-')
                    ->toggleable()
                    ->visibleFrom('2xl'),
                TextColumn::make('transaction_comparison')
                    ->label('Vs transakcje')
                    ->state(fn (Listing $record) => $record->transaction_comparison['diff_percent'] ?? null)
                    ->formatStateUsing(fn ($state, Listing $record) => ($state > 0 ? '+' : '').round($state).'%'.(! empty($record->transaction_comparison['approximate']) ? ' ~' : ''))
                    ->badge()
                    ->color(fn (Listing $record) => ! empty($record->transaction_comparison['approximate']) ? 'gray' : match ($record->transaction_comparison['status'] ?? null) {
                        TransactionComparator::ABOVE => 'danger',
                        TransactionComparator::BELOW => 'success',
                        default => 'gray',
                    })
                    ->tooltip(fn (Listing $record) => TransactionComparator::describe($record->transaction_comparison))
                    ->placeholder('-')
                    ->toggleable(isToggledHiddenByDefault: true)
                    ->visibleFrom('2xl'),
                TextColumn::make('portal')
                    ->label('Portal')
                    ->badge()
                    ->formatStateUsing(fn (string $state) => Portals::label($state))
                    ->color(fn (string $state) => Portals::color($state))
                    ->toggleable(isToggledHiddenByDefault: true)
                    ->visibleFrom('2xl'),
                TextColumn::make('status')
                    ->label('Status')
                    ->state(fn (Listing $record) => array_values(array_filter([
                        $record->status?->getLabel(),
                        $record->risk_flags ? '⚠ '.count($record->risk_flags) : null,
                    ])))
                    ->badge()
                    ->color(fn (string $state, Listing $record) => str_starts_with($state, '⚠') ? 'danger' : ($record->status?->getColor() ?? 'gray'))
                    ->tooltip(fn (Listing $record) => $record->risk_flags ? collect($record->risk_flags)->pluck('message')->implode("\n") : null)
                    ->placeholder('-')
                    ->visibleFrom(ListingCard::BREAKPOINT),
                TextColumn::make('first_seen_at')
                    ->label('Znaleziona')
                    ->since()
                    ->dateTimeTooltip('d.m.Y H:i')
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true)
                    ->visibleFrom(ListingCard::BREAKPOINT),
            ])
            ->filters([
                ListingFilters::aboveThreshold(),
                SelectFilter::make('search')
                    ->label('Wyszukiwanie')
                    ->relationship('searches', 'name'),
                SelectFilter::make('deal_type')
                    ->label('Rodzaj')
                    ->options(DealType::class),
                Filter::make('min_score')
                    ->schema([
                        Select::make('score')
                            ->label('Ocena AI od')
                            ->options(array_combine(range(1, 10), range(1, 10))),
                    ])
                    ->query(fn (Builder $query, array $data) => $query->when(
                        $data['score'] ?? null,
                        fn (Builder $query, $score) => $query->whereHas('analyses', fn (Builder $query) => $query->where('score', '>=', $score)),
                    ))
                    ->indicateUsing(fn (array $data) => filled($data['score'] ?? null) ? "Ocena od {$data['score']}" : null),
                SelectFilter::make('status')
                    ->label('Status')
                    ->options(ListingStatus::class),
                Filter::make('commute')
                    ->schema([
                        Select::make('place')
                            ->label('Dojazd autem do')
                            ->options(fn () => CommutePlace::where('is_active', true)->pluck('name', 'id')),
                        TextInput::make('minutes')
                            ->label('najwyżej')
                            ->integer()
                            ->suffix('min'),
                    ])
                    ->query(fn (Builder $query, array $data) => $query->when(
                        filled($data['place'] ?? null) && filled($data['minutes'] ?? null),
                        fn (Builder $query) => $query->whereHas('commutes', fn (Builder $query) => $query
                            ->where('commute_place_id', $data['place'])
                            ->where('mode', 'car')
                            ->where('minutes', '<=', (int) $data['minutes'])),
                    ))
                    ->indicateUsing(fn (array $data) => filled($data['place'] ?? null) && filled($data['minutes'] ?? null)
                        ? 'Dojazd do '.CommutePlace::find($data['place'])?->name." do {$data['minutes']} min" : null),
                SelectFilter::make('transactions')
                    ->label('Cena wobec transakcji (sprzedaż)')
                    ->options([
                        TransactionComparator::BELOW => 'Taniej niż transakcje w okolicy',
                        TransactionComparator::MARKET => 'W granicach rynku',
                        TransactionComparator::ABOVE => 'Drożej niż transakcje w okolicy',
                    ])
                    ->query(fn (Builder $query, array $data) => $query->when($data['value'] ?? null, fn (Builder $query, string $status) => $query->where('transaction_comparison->status', $status))),
                Filter::make('risky')
                    ->label('Tylko z sygnałami ryzyka')
                    ->toggle()
                    ->query(fn (Builder $query) => $query->whereNotNull('risk_flags')),
                SelectFilter::make('portal')
                    ->label('Portal')
                    ->options(Portals::allOptions()),
                Filter::make('hide_rejected')
                    ->label('Ukryj odrzucone')
                    ->default()
                    ->query(fn (Builder $query) => $query->where(fn (Builder $query) => $query->whereNull('status')->orWhere('status', '!=', ListingStatus::Rejected->value))),
                Filter::make('hide_duplicates')
                    ->label('Ukryj duplikaty z drugiego portalu')
                    ->default()
                    ->query(fn (Builder $query) => $query->whereNull('duplicate_of_id')),
            ])
            ->filtersFormColumns(2)
            ->persistFiltersInSession()
            ->recordActions([
                Action::make('open')
                    ->label('Otwórz ogłoszenie')
                    ->tooltip('Otwórz ogłoszenie na portalu')
                    ->iconButton()
                    ->icon(Heroicon::OutlinedArrowTopRightOnSquare)
                    ->url(fn (Listing $record) => $record->url, shouldOpenInNewTab: true),
                ActionGroup::make([
                    ...collect(ListingStatus::cases())->map(fn (ListingStatus $status) => Action::make("status_{$status->value}")
                        ->label($status->getLabel())
                        ->color($status->getColor())
                        ->action(fn (Listing $record) => $record->update(['status' => $status]))),
                    Action::make('status_clear')
                        ->label('Bez statusu')
                        ->action(fn (Listing $record) => $record->update(['status' => null])),
                    EditAction::make()->label('Notatka'),
                ])->label('Status')->tooltip('Status i notatka')->iconButton()->icon(Heroicon::OutlinedTag),
            ])
            ->toolbarActions([
                BulkAction::make('reject')
                    ->label('Odrzuć zaznaczone')
                    ->icon(Heroicon::OutlinedXMark)
                    ->deselectRecordsAfterCompletion()
                    ->action(fn (Collection $records) => Listing::whereKey($records->modelKeys())->update(['status' => ListingStatus::Rejected->value])),
            ]);
    }
}
