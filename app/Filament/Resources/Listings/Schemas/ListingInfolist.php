<?php

namespace App\Filament\Resources\Listings\Schemas;

use App\Filament\Support\Format;
use App\Models\Listing;
use App\Models\ListingAnalysis;
use App\Models\RcnTransaction;
use App\Services\Rcn\TransactionComparator;
use App\Support\Portals;
use Filament\Infolists\Components\ImageEntry;
use Filament\Infolists\Components\RepeatableEntry;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

class ListingInfolist
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->columns(3)
            ->components([
                Section::make('Oferta')
                    ->columnSpan(2)
                    ->columns(3)
                    ->schema([
                        TextEntry::make('title')
                            ->hiddenLabel()
                            ->size('lg')
                            ->weight('bold')
                            ->columnSpanFull(),
                        TextEntry::make('url')
                            ->hiddenLabel()
                            ->formatStateUsing(fn (Listing $record) => 'Otwórz ogłoszenie na '.Portals::label($record->portal))
                            ->url(fn (Listing $record) => $record->url, shouldOpenInNewTab: true)
                            ->color('primary')
                            ->icon('heroicon-o-arrow-top-right-on-square')
                            ->columnSpanFull(),
                        TextEntry::make('price')
                            ->label(fn (Listing $record) => $record->deal_type->value === 'rent' ? 'Najem' : 'Cena')
                            ->formatStateUsing(fn ($state) => Format::pln($state))
                            ->placeholder('nie podano'),
                        TextEntry::make('rent_extra')
                            ->label('Czynsz')
                            ->formatStateUsing(fn ($state) => Format::pln($state))
                            ->placeholder('nie podano'),
                        TextEntry::make('price_per_m2')
                            ->label('Cena za m²')
                            ->formatStateUsing(fn ($state) => Format::pln($state))
                            ->placeholder('-'),
                        TextEntry::make('area')
                            ->label('Metraż')
                            ->formatStateUsing(fn ($state) => Format::area($state))
                            ->placeholder('-'),
                        TextEntry::make('rooms')
                            ->label('Pokoje')
                            ->placeholder('-'),
                        TextEntry::make('floor')
                            ->label('Piętro')
                            ->placeholder('-'),
                        TextEntry::make('city')
                            ->label('Lokalizacja')
                            ->state(fn (Listing $record) => collect([$record->city, $record->district, $record->street])->filter()->unique()->implode(' · '))
                            ->columnSpan(2),
                        TextEntry::make('is_private_owner')
                            ->label('Ogłoszeniodawca')
                            ->formatStateUsing(fn ($state) => $state ? 'osoba prywatna' : 'agencja / firma')
                            ->placeholder('nie wiadomo'),
                        TextEntry::make('first_seen_at')
                            ->label('Znaleziona')
                            ->dateTime('d.m.Y H:i'),
                        TextEntry::make('last_seen_at')
                            ->label('Ostatnio na portalu')
                            ->dateTime('d.m.Y H:i'),
                        TextEntry::make('searches.name')
                            ->label('Pasuje do')
                            ->badge(),
                    ]),

                Grid::make(1)
                    ->columnSpan(1)
                    ->schema([
                        ImageEntry::make('image_url')
                            ->hiddenLabel()
                            ->imageWidth('100%')
                            ->imageHeight('auto')
                            ->extraImgAttributes(['class' => 'rounded-lg'])
                            ->visible(fn (Listing $record) => filled($record->image_url)),
                        Section::make('Twój status')
                            ->schema([
                                TextEntry::make('status')
                                    ->hiddenLabel()
                                    ->badge()
                                    ->placeholder('Brak statusu'),
                                TextEntry::make('note')
                                    ->hiddenLabel()
                                    ->placeholder('Bez notatki'),
                            ]),
                    ]),

                Section::make('Sygnały ryzyka')
                    ->icon('heroicon-o-exclamation-triangle')
                    ->iconColor('danger')
                    ->columnSpanFull()
                    ->visible(fn (Listing $record) => filled($record->risk_flags))
                    ->schema([
                        TextEntry::make('risk_flags')
                            ->hiddenLabel()
                            ->state(fn (Listing $record) => collect($record->risk_flags)->pluck('message')->all())
                            ->listWithLineBreaks()
                            ->bulleted()
                            ->color('danger'),
                    ]),

                Section::make('Ceny transakcyjne w okolicy')
                    ->icon('heroicon-o-document-text')
                    ->columnSpanFull()
                    ->collapsible()
                    ->visible(fn (Listing $record) => filled($record->transaction_comparison))
                    ->description(fn (Listing $record) => 'Prawdziwe ceny sprzedaży mieszkań z Rejestru Cen Nieruchomości (akty notarialne), podobny metraż.'
                        .(in_array($record->geo_precision, ['district', 'city'], true) ? ' Położenie oferty jest przybliżone, więc okolica jest szersza.' : ''))
                    ->schema([
                        TextEntry::make('transactionSummary')
                            ->hiddenLabel()
                            ->state(fn (Listing $record) => TransactionComparator::describe($record->transaction_comparison))
                            ->weight('bold')
                            ->color(fn (Listing $record) => match ($record->transaction_comparison['status'] ?? null) {
                                TransactionComparator::ABOVE => 'danger',
                                TransactionComparator::BELOW => 'success',
                                default => null,
                            }),
                        Grid::make(4)
                            ->visible(fn (Listing $record) => isset($record->transaction_comparison['median_price_per_m2']))
                            ->schema([
                                TextEntry::make('tc_offer')->label('Oferta za m²')->state(fn (Listing $record) => Format::pln($record->transaction_comparison['offer_price_per_m2'] ?? null)),
                                TextEntry::make('tc_median')->label('Mediana transakcji za m²')->state(fn (Listing $record) => Format::pln($record->transaction_comparison['median_price_per_m2'] ?? null)),
                                TextEntry::make('tc_range')->label('Połowa transakcji w przedziale')->state(fn (Listing $record) => isset($record->transaction_comparison['p25_price_per_m2'])
                                    ? Format::pln($record->transaction_comparison['p25_price_per_m2']).' – '.Format::pln($record->transaction_comparison['p75_price_per_m2']) : null),
                                TextEntry::make('tc_latest')->label('Najnowsza transakcja')->state(fn (Listing $record) => $record->transaction_comparison['latest_date'] ?? null)->date('d.m.Y'),
                            ]),
                        RepeatableEntry::make('tc_nearest')
                            ->label('Najbliższe transakcje')
                            ->visible(fn (Listing $record) => filled($record->transaction_comparison['nearest'] ?? null))
                            ->state(fn (Listing $record) => collect($record->transaction_comparison['nearest'] ?? [])->map(fn (array $row) => $row + [
                                'rooms_floor' => ($row['rooms'] ?? '?').' / '.($row['floor'] ?? '?'),
                                'where' => $row['distance_m'].' m, '.match ($row['market'] ?? null) {
                                    RcnTransaction::PRIMARY => 'pierwotny',
                                    RcnTransaction::SECONDARY => 'wtórny',
                                    default => 'rynek ?',
                                },
                            ])->all())
                            ->columns(6)
                            ->schema([
                                TextEntry::make('date')->label('Data')->date('m.Y'),
                                TextEntry::make('area')->label('Metraż')->formatStateUsing(fn ($state) => Format::area($state)),
                                TextEntry::make('rooms_floor')->label('Izby / piętro'),
                                TextEntry::make('price')->label('Cena')->formatStateUsing(fn ($state) => Format::pln($state)),
                                TextEntry::make('price_per_m2')->label('Za m²')->formatStateUsing(fn ($state) => Format::pln($state)),
                                TextEntry::make('where')->label('Odległość, rynek'),
                            ]),
                    ]),

                Section::make('Dojazd')
                    ->icon('heroicon-o-map-pin')
                    ->columnSpanFull()
                    ->visible(fn (Listing $record) => $record->commutes()->exists())
                    ->description(fn (Listing $record) => match ($record->geo_precision) {
                        'district' => 'Położenie przybliżone (środek dzielnicy), czasy mogą się różnić.',
                        'city' => 'Położenie przybliżone (środek miasta), czasy mogą się różnić.',
                        default => 'Trasa bez korków, liczona na naszym serwerze.',
                    })
                    ->schema([
                        RepeatableEntry::make('commutesByPlace')
                            ->hiddenLabel()
                            ->state(fn (Listing $record) => $record->commutes()->with('place')->get()
                                ->filter(fn ($commute) => $commute->place?->is_active)
                                ->groupBy('commute_place_id')
                                ->map(fn ($commutes) => [
                                    'place' => $commutes->first()->place->name,
                                    'car' => ($car = $commutes->firstWhere('mode', 'car')) ? "{$car->minutes} min ({$car->km} km)" : null,
                                    'bike' => ($bike = $commutes->firstWhere('mode', 'bike')) ? "{$bike->minutes} min ({$bike->km} km)" : null,
                                    'route' => "https://www.openstreetmap.org/directions?engine=fossgis_osrm_car&route={$record->lat},{$record->lng};{$commutes->first()->place->lat},{$commutes->first()->place->lng}",
                                ])->values()->all())
                            ->columns(4)
                            ->schema([
                                TextEntry::make('place')->label('Miejsce')->weight('bold'),
                                TextEntry::make('car')->label('🚗 Autem')->placeholder('-'),
                                TextEntry::make('bike')->label('🚲 Rowerem')->placeholder('-'),
                                TextEntry::make('route')->label('Trasa')->formatStateUsing(fn () => 'Pokaż na mapie')->url(fn ($state) => $state, shouldOpenInNewTab: true)->color('primary'),
                            ]),
                    ]),

                Section::make('Ocena AI')
                    ->columnSpanFull()
                    ->schema([
                        RepeatableEntry::make('analyses')
                            ->hiddenLabel()
                            ->placeholder('Brak oceny AI (AI wyłączone albo błąd).')
                            ->columns(4)
                            ->schema([
                                TextEntry::make('score')
                                    ->label(fn (?ListingAnalysis $record) => 'Ocena: '.($record?->search?->name ?? 'wyszukiwanie'))
                                    ->badge()
                                    ->size('lg')
                                    ->color(fn ($state) => Format::scoreColor($state))
                                    ->formatStateUsing(fn ($state) => "{$state}/10"),
                                TextEntry::make('monthly_cost')
                                    ->label('Koszt miesięczny')
                                    ->formatStateUsing(fn ($state) => Format::pln($state))
                                    ->placeholder('nie wiadomo'),
                                TextEntry::make('sub_scores')
                                    ->label('Oceny cząstkowe')
                                    ->state(fn (ListingAnalysis $record) => collect([
                                        'cost' => 'Koszt',
                                        'location' => 'Lokalizacja',
                                        'standard' => 'Standard',
                                        'fit' => 'Dopasowanie',
                                    ])->filter(fn ($label, $key) => isset($record->sub_scores[$key]))
                                        ->map(fn ($label, $key) => "{$label} {$record->sub_scores[$key]}")
                                        ->values()->all())
                                    ->badge()
                                    ->color('gray')
                                    ->columnSpan(2),
                                TextEntry::make('summary')
                                    ->label('Podsumowanie')
                                    ->columnSpanFull(),
                                TextEntry::make('cost_breakdown.note')
                                    ->label('Koszt: szczegóły')
                                    ->placeholder('-')
                                    ->columnSpanFull(),
                                TextEntry::make('pros')
                                    ->label('Plusy')
                                    ->listWithLineBreaks()
                                    ->bulleted()
                                    ->placeholder('-')
                                    ->columnSpan(2),
                                TextEntry::make('red_flags')
                                    ->label('Uwaga')
                                    ->listWithLineBreaks()
                                    ->bulleted()
                                    ->color('danger')
                                    ->placeholder('-')
                                    ->columnSpan(2),
                            ]),
                    ]),

                Section::make('Historia cen')
                    ->columnSpanFull()
                    ->collapsible()
                    ->visible(fn (Listing $record) => $record->priceChanges()->exists())
                    ->schema([
                        RepeatableEntry::make('priceChanges')
                            ->hiddenLabel()
                            ->columns(3)
                            ->schema([
                                TextEntry::make('changed_at')->label('Kiedy')->dateTime('d.m.Y H:i'),
                                TextEntry::make('old_price')->label('Było')->formatStateUsing(fn ($state) => Format::pln($state)),
                                TextEntry::make('new_price')->label('Jest')->formatStateUsing(fn ($state) => Format::pln($state)),
                            ]),
                    ]),

                Section::make('Opis z ogłoszenia')
                    ->columnSpanFull()
                    ->collapsible()
                    ->schema([
                        TextEntry::make('description')
                            ->hiddenLabel()
                            ->placeholder('Portal nie podał opisu.')
                            ->extraAttributes(['class' => 'whitespace-pre-line']),
                    ]),
            ]);
    }
}
