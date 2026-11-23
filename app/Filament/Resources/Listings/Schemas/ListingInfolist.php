<?php

namespace App\Filament\Resources\Listings\Schemas;

use App\Filament\Pages\MortgagePage;
use App\Filament\Support\Format;
use App\Models\Listing;
use App\Models\ListingAnalysis;
use App\Models\Poi;
use App\Models\RcnTransaction;
use App\Services\DealFinder;
use App\Services\Geo\Infrastructure;
use App\Services\ListingFacts;
use App\Services\ListingFeatures;
use App\Services\Mortgage\RkmSimulator;
use App\Services\Rcn\TransactionComparator;
use App\Support\Portals;
use Filament\Infolists\Components\ImageEntry;
use Filament\Infolists\Components\RepeatableEntry;
use Filament\Infolists\Components\TextEntry;
use Filament\Infolists\Components\ViewEntry;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Illuminate\Support\Carbon;

class ListingInfolist
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->columns(['default' => 1, 'lg' => 3])
            ->components([
                Section::make('Oferta')
                    ->columnSpan(['default' => 1, 'lg' => 2])
                    ->columns(['default' => 2, 'sm' => 3])
                    ->schema([
                        // Etykiety: garaż i komórka (w cenie / za dopłatą), ogrzewanie, rok budowy, winda, balkon.
                        TextEntry::make('facts_badges')
                            ->hiddenLabel()
                            ->state(fn (Listing $record) => array_keys(ListingFacts::badges($record)))
                            ->badge()
                            ->color(fn (string $state, Listing $record) => ListingFacts::badges($record)[$state] ?? 'gray')
                            ->columnSpanFull()
                            ->visible(fn (Listing $record) => ListingFacts::badges($record) !== []),
                        TextEntry::make('best_score')
                            ->label('Ocena AI')
                            ->state(fn (Listing $record) => $record->analyses->max('score'))
                            ->badge()
                            ->size('lg')
                            ->color(fn ($state) => Format::scoreColor($state))
                            ->formatStateUsing(fn ($state) => "{$state}/10")
                            ->placeholder('brak'),
                        TextEntry::make('price')
                            ->label(fn (Listing $record) => $record->deal_type->value === 'rent' ? 'Najem' : 'Cena')
                            ->formatStateUsing(fn ($state) => Format::pln($state))
                            ->size('lg')
                            ->weight('bold')
                            ->placeholder('nie podano'),
                        TextEntry::make('monthly_cost')
                            ->label('Koszt miesięczny (AI)')
                            ->state(fn (Listing $record) => $record->analyses->max('monthly_cost'))
                            ->formatStateUsing(fn ($state) => Format::pln($state))
                            ->size('lg')
                            ->placeholder('nie wiadomo')
                            ->visible(fn (Listing $record) => $record->deal_type->value === 'rent'),
                        TextEntry::make('price_per_m2')
                            ->label('Cena za m²')
                            ->formatStateUsing(fn ($state) => Format::pln($state))
                            ->size('lg')
                            ->placeholder('-')
                            ->visible(fn (Listing $record) => $record->deal_type->value !== 'rent'),
                        TextEntry::make('mortgage')
                            ->label('Rata kredytu (RKM)')
                            ->state(function (Listing $record) {
                                $result = app(RkmSimulator::class)->simulate(RkmSimulator::defaults($record));

                                return Format::pln((int) round($result['installment'])).' / mies.';
                            })
                            ->helperText(fn (Listing $record) => 'stałe '.str_replace('.', ',', (string) config('mortgage.fixed_rate')).'% przez 5 lat, 30 lat'
                                .(RkmSimulator::requiredOwn((float) $record->price) > 0 ? ', wkład '.RkmSimulator::money(RkmSimulator::requiredOwn((float) $record->price)) : ', bez wkładu'))
                            ->url(fn (Listing $record) => MortgagePage::getUrl(['listing' => $record->id]))
                            ->color('primary')
                            ->visible(fn (Listing $record) => $record->deal_type->value === 'sale' && $record->price),
                        TextEntry::make('rent_extra')
                            ->label('Czynsz')
                            ->state(function (Listing $record) {
                                [$fee, $source] = $record->adminFee();

                                return $fee === null ? null : Format::pln($fee).match ($source) {
                                    'description' => ' (z opisu, odczytane przez AI)',
                                    'duplicate' => ' (z innego portalu)',
                                    default => '',
                                };
                            })
                            ->placeholder('nie podano')
                            ->visible(fn (Listing $record) => $record->deal_type->value === 'rent'),
                        TextEntry::make('area')
                            ->label('Metraż')
                            ->formatStateUsing(fn ($state) => Format::area($state))
                            ->placeholder('-'),
                        TextEntry::make('rooms_floor')
                            ->label('Pokoje / piętro')
                            ->state(fn (Listing $record) => ($record->rooms ?? '?').' / '.($record->floor ?? '?')),
                        TextEntry::make('deal_reasons')
                            ->label('Okazja')
                            ->state(fn (Listing $record) => DealFinder::summary($record->deal_reasons))
                            ->color('success')
                            ->weight('medium')
                            ->icon('heroicon-o-fire')
                            ->columnSpanFull()
                            ->visible(fn (Listing $record) => filled($record->deal_reasons)),
                        TextEntry::make('market_furniture')
                            ->label('Rynek / stan')
                            ->state(function (Listing $record) {
                                $features = ListingFeatures::of($record);

                                return collect([
                                    'rynek '.$features->marketLabel(),
                                    match ($features->ready) {
                                        true => 'gotowe do zamieszkania',
                                        false => 'do wykończenia lub remontu',
                                        null => 'stan nieznany',
                                    },
                                    match ($features->furnished) {
                                        true => 'umeblowane',
                                        false => 'bez mebli',
                                        null => null,
                                    },
                                    $features->visualizations ? 'wizualizacje' : null,
                                ])->filter()->implode(', ');
                            })
                            ->visible(fn (Listing $record) => $record->deal_type->value === 'sale'),
                        TextEntry::make('also_on')
                            ->label('Też na')
                            ->state(fn (Listing $record) => $record->duplicates->map(fn (Listing $duplicate) => '<a href="'.e($duplicate->url).'" target="_blank" rel="noopener" class="pk-rows__link">'.e(Portals::label($duplicate->portal)).'</a>'
                                .($duplicate->price !== $record->price && $duplicate->price ? ' ('.e(Format::pln($duplicate->price)).')' : ''))->implode(', '))
                            ->html()
                            ->visible(fn (Listing $record) => $record->duplicates->isNotEmpty()),
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
                            ->badge()
                            ->columnSpan(['default' => 2, 'sm' => 1]),
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

                Section::make('Mieszkanie i budynek')
                    ->icon('heroicon-o-building-office-2')
                    ->columnSpanFull()
                    ->collapsible()
                    ->description('Z danych portalu, a gdy ich brak, z opisu ogłoszenia.')
                    ->columns(['default' => 2, 'md' => 4])
                    ->visible(fn (Listing $record) => ListingFacts::labels($record) !== [])
                    ->schema([
                        TextEntry::make('fact_0')
                            ->label('Ogrzewanie')
                            ->state(fn (Listing $record) => ListingFacts::labels($record)['Ogrzewanie'] ?? null)
                            ->visible(fn (Listing $record) => isset(ListingFacts::labels($record)['Ogrzewanie'])),
                        TextEntry::make('fact_1')
                            ->label('Rok budowy')
                            ->state(fn (Listing $record) => ListingFacts::labels($record)['Rok budowy'] ?? null)
                            ->visible(fn (Listing $record) => isset(ListingFacts::labels($record)['Rok budowy'])),
                        TextEntry::make('fact_2')
                            ->label('Pięter w budynku')
                            ->state(fn (Listing $record) => ListingFacts::labels($record)['Pięter w budynku'] ?? null)
                            ->visible(fn (Listing $record) => isset(ListingFacts::labels($record)['Pięter w budynku'])),
                        TextEntry::make('fact_3')
                            ->label('Winda')
                            ->state(fn (Listing $record) => ListingFacts::labels($record)['Winda'] ?? null)
                            ->visible(fn (Listing $record) => isset(ListingFacts::labels($record)['Winda'])),
                        TextEntry::make('fact_4')
                            ->label('Balkon / taras')
                            ->state(fn (Listing $record) => ListingFacts::labels($record)['Balkon / taras'] ?? null)
                            ->visible(fn (Listing $record) => isset(ListingFacts::labels($record)['Balkon / taras'])),
                        TextEntry::make('fact_5')
                            ->label('Garaż / parking')
                            ->state(fn (Listing $record) => ListingFacts::labels($record)['Garaż / parking'] ?? null)
                            ->visible(fn (Listing $record) => isset(ListingFacts::labels($record)['Garaż / parking'])),
                        TextEntry::make('fact_6')
                            ->label('Komórka / piwnica')
                            ->state(fn (Listing $record) => ListingFacts::labels($record)['Komórka / piwnica'] ?? null)
                            ->visible(fn (Listing $record) => isset(ListingFacts::labels($record)['Komórka / piwnica'])),
                        TextEntry::make('fact_7')
                            ->label('Stan')
                            ->state(fn (Listing $record) => ListingFacts::labels($record)['Stan'] ?? null)
                            ->visible(fn (Listing $record) => isset(ListingFacts::labels($record)['Stan'])),
                        TextEntry::make('fact_8')
                            ->label('Kaucja')
                            ->state(fn (Listing $record) => ListingFacts::labels($record)['Kaucja'] ?? null)
                            ->visible(fn (Listing $record) => isset(ListingFacts::labels($record)['Kaucja'])),
                        TextEntry::make('fact_9')
                            ->label('Dostępne od')
                            ->state(fn (Listing $record) => ListingFacts::labels($record)['Dostępne od'] ?? null)
                            ->visible(fn (Listing $record) => isset(ListingFacts::labels($record)['Dostępne od'])),
                    ]),

                Section::make('Infrastruktura')
                    ->icon('heroicon-o-shopping-cart')
                    ->columnSpanFull()
                    ->collapsible()
                    ->description(fn (Listing $record) => 'Najbliższe sklepy, żłobek, przedszkole, szkoły, przychodnie (OpenStreetMap) i szpital. Odległość drogą, czas pieszo, rowerem i autem. Przychodnia POZ zwykle ma pediatrę, ale OpenStreetMap tego nie potwierdza.'
                        .(in_array($record->geo_precision, ['district', 'city'], true) ? ' Położenie oferty jest przybliżone, więc odległości też.' : ''))
                    ->schema([
                        ViewEntry::make('infrastructureRows')
                            ->hiddenLabel()
                            ->state(fn (Listing $record) => self::infrastructureRows($record))
                            ->view('filament.listings.rows-table', ['columns' => [
                                'category' => 'Co', 'name' => 'Najbliżej', 'address' => 'Adres', 'distance' => 'Odległość', 'walk' => 'Pieszo', 'bike' => 'Rowerem', 'car' => 'Autem',
                            ]])
                            ->visible(fn (Listing $record) => filled($record->infrastructure)),
                        TextEntry::make('infrastructureMissing')
                            ->hiddenLabel()
                            ->state('Policzy się, gdy oferta będzie miała ustalone położenie (zwykle w ciągu kilku minut).')
                            ->color('gray')
                            ->visible(fn (Listing $record) => blank($record->infrastructure)),
                    ]),

                Section::make('Ocena AI')
                    ->columnSpanFull()
                    ->schema([
                        RepeatableEntry::make('analyses')
                            ->hiddenLabel()
                            ->placeholder('Brak oceny AI (AI wyłączone albo błąd).')
                            ->columns(['default' => 2, 'md' => 4])
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
                                    ->columnSpan(['default' => 2, 'md' => 2]),
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
                                    ->columnSpan(['default' => 2, 'md' => 2]),
                                TextEntry::make('red_flags')
                                    ->label('Uwaga')
                                    ->listWithLineBreaks()
                                    ->bulleted()
                                    ->color('danger')
                                    ->placeholder('-')
                                    ->columnSpan(['default' => 2, 'md' => 2]),
                            ]),
                    ]),

                Section::make('Kredyt (Rodzinny Kredyt Mieszkaniowy)')
                    ->icon('heroicon-o-banknotes')
                    ->columnSpanFull()
                    ->collapsible()
                    ->description('Bez wkładu własnego, z gwarancją BGK. Cena, metraż i miejscowość z oferty, 30 lat.')
                    ->visible(fn (Listing $record) => $record->deal_type->value === 'sale' && $record->price)
                    ->schema([
                        ViewEntry::make('mortgageSimulation')
                            ->hiddenLabel()
                            ->state(function (Listing $record) {
                                $simulator = app(RkmSimulator::class);
                                $input = RkmSimulator::defaults($record);

                                return [
                                    'fixed' => $simulator->simulate(['rate_type' => RkmSimulator::FIXED] + $input),
                                    'variable' => $simulator->simulate(['rate_type' => RkmSimulator::VARIABLE] + $input),
                                    'url' => MortgagePage::getUrl(['listing' => $record->id]),
                                ];
                            })
                            ->view('filament.listings.mortgage'),
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
                        Grid::make(['default' => 2, 'md' => 4])
                            ->visible(fn (Listing $record) => isset($record->transaction_comparison['median_price_per_m2']))
                            ->schema([
                                TextEntry::make('tc_offer')->label('Oferta za m²')->state(fn (Listing $record) => Format::pln($record->transaction_comparison['offer_price_per_m2'] ?? null)),
                                TextEntry::make('tc_median')->label('Mediana transakcji za m²')->state(fn (Listing $record) => Format::pln($record->transaction_comparison['median_price_per_m2'] ?? null)),
                                TextEntry::make('tc_range')->label('Połowa transakcji w przedziale')->state(fn (Listing $record) => isset($record->transaction_comparison['p25_price_per_m2'])
                                    ? Format::pln($record->transaction_comparison['p25_price_per_m2']).' – '.Format::pln($record->transaction_comparison['p75_price_per_m2']) : null),
                                TextEntry::make('tc_latest')->label('Najnowsza transakcja')->state(fn (Listing $record) => $record->transaction_comparison['latest_date'] ?? null)->date('d.m.Y'),
                            ]),
                        ViewEntry::make('tc_nearest')
                            ->label('Najbliższe transakcje')
                            ->view('filament.listings.rows-table', ['columns' => [
                                'date' => 'Data', 'area' => 'Metraż', 'rooms_floor' => 'Izby / piętro',
                                'price' => 'Cena', 'price_per_m2' => 'Za m²', 'distance' => 'Odległość', 'market' => 'Rynek',
                            ]])
                            ->visible(fn (Listing $record) => filled($record->transaction_comparison['nearest'] ?? null))
                            ->state(fn (Listing $record) => collect($record->transaction_comparison['nearest'] ?? [])->map(fn (array $row) => [
                                'date' => isset($row['date']) ? Carbon::parse($row['date'])->format('m.Y') : null,
                                'area' => Format::area($row['area'] ?? null),
                                'rooms_floor' => ($row['rooms'] ?? '?').' / '.($row['floor'] ?? '?'),
                                'price' => Format::pln($row['price'] ?? null),
                                'price_per_m2' => Format::pln($row['price_per_m2'] ?? null),
                                'distance' => isset($row['distance_m']) ? $row['distance_m'].' m' : null,
                                'market' => match ($row['market'] ?? null) {
                                    RcnTransaction::PRIMARY => 'pierwotny',
                                    RcnTransaction::SECONDARY => 'wtórny',
                                    default => 'nie wiadomo',
                                },
                            ])->all()),
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
                        ViewEntry::make('commutesByPlace')
                            ->hiddenLabel()
                            ->view('filament.listings.rows-table', ['columns' => ['place' => 'Miejsce', 'car' => '🚗 Autem', 'bike' => '🚲 Rowerem', 'route' => 'Trasa']])
                            ->state(fn (Listing $record) => $record->commutes()->with('place')->get()
                                ->filter(fn ($commute) => $commute->place?->is_active)
                                ->groupBy('commute_place_id')
                                ->map(fn ($commutes) => [
                                    'place' => $commutes->first()->place->name,
                                    'car' => ($car = $commutes->firstWhere('mode', 'car')) ? "{$car->minutes} min ({$car->km} km)" : null,
                                    'bike' => ($bike = $commutes->firstWhere('mode', 'bike')) ? "{$bike->minutes} min ({$bike->km} km)" : null,
                                    'route' => 'Pokaż',
                                    'route_url' => "https://www.openstreetmap.org/directions?engine=fossgis_osrm_car&route={$record->lat},{$record->lng};{$commutes->first()->place->lat},{$commutes->first()->place->lng}",
                                ])->values()->all()),
                    ]),

                Section::make('Historia cen')
                    ->columnSpanFull()
                    ->collapsible()
                    ->visible(fn (Listing $record) => $record->priceChanges()->exists())
                    ->schema([
                        ViewEntry::make('priceHistory')
                            ->hiddenLabel()
                            ->view('filament.listings.rows-table', ['columns' => ['changed_at' => 'Kiedy', 'old_price' => 'Było', 'new_price' => 'Jest']])
                            ->state(fn (Listing $record) => $record->priceChanges()->latest('changed_at')->get()->map(fn ($change) => [
                                'changed_at' => $change->changed_at->format('d.m.Y H:i'),
                                'old_price' => Format::pln($change->old_price),
                                'new_price' => Format::pln($change->new_price),
                            ])->all()),
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

    /**
     * Wiersze tabeli Infrastruktura: każdy sklep osobno, potem żłobek, przedszkole, szkoły.
     *
     * @return list<array<string, string>>
     */
    private static function infrastructureRows(Listing $listing): array
    {
        $rows = [];

        foreach (Poi::LABELS as $category => $label) {
            $pois = $listing->infrastructure['items'][$category] ?? [];

            if ($pois === []) {
                $rows[] = ['category' => $label, 'name' => 'brak w promieniu '.config('infrastructure.search_km').' km', 'address' => '-', 'distance' => '-', 'walk' => '-', 'bike' => '-', 'car' => '-'];

                continue;
            }

            foreach ($pois as $i => $poi) {
                $rows[] = [
                    'category' => $i === 0 ? $label : '',
                    'name' => $poi['name'],
                    'address' => $poi['address'] ?? '-',
                    'distance' => Infrastructure::km((float) $poi['km']).($poi['by_road'] ? '' : ' (w linii prostej)'),
                    'walk' => $poi['walk_min'].' min',
                    'bike' => $poi['bike_min'] ? $poi['bike_min'].' min' : '-',
                    'car' => ($poi['car_min'] ?? null) ? $poi['car_min'].' min' : '-',
                ];
            }
        }

        return $rows;
    }
}
