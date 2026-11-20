@php
    use App\Filament\Pages\MarketReportPage;
    use App\Filament\Support\Format;
    use Illuminate\Support\Str;

    $current = $this->current;
    $data = $current['data'] ?? null;
@endphp

<x-filament-panels::page>
    <style>
        .pk-report__stats { display: grid; grid-template-columns: repeat(2, minmax(0, 1fr)); gap: 1rem; }
        @media (min-width: 1024px) { .pk-report__stats { grid-template-columns: repeat(4, minmax(0, 1fr)); } }
        .pk-report__label { font-size: .8125rem; color: var(--gray-500); }
        .pk-report__value { font-size: 1.5rem; font-weight: 600; color: var(--gray-950); margin-top: .25rem; }
        .dark .pk-report__value { color: white; }
        .pk-report__change { font-size: .8125rem; color: var(--gray-500); margin-top: .125rem; }
        .pk-report__grid { display: grid; grid-template-columns: 1fr; gap: 1.5rem; }
        @media (min-width: 1024px) { .pk-report__grid { grid-template-columns: 1fr 1fr; } }
        .pk-report table { width: 100%; border-collapse: collapse; font-size: .875rem; }
        .pk-report th { text-align: left; font-weight: 500; color: var(--gray-500); padding: .375rem .5rem; border-bottom: 1px solid var(--gray-200); }
        .pk-report td { padding: .375rem .5rem; border-bottom: 1px solid var(--gray-100); color: var(--gray-950); }
        .dark .pk-report td { color: var(--gray-100); border-color: rgb(255 255 255 / .06); }
        .dark .pk-report th { border-color: rgb(255 255 255 / .1); }
        .pk-report td.num, .pk-report th.num { text-align: right; font-variant-numeric: tabular-nums; white-space: nowrap; }
        .pk-report a { color: var(--primary-600); } .dark .pk-report a { color: var(--primary-400); }
        .pk-report__muted { color: var(--gray-500); font-size: .875rem; }
        .pk-report__select { width: 100%; max-width: 28rem; }
    </style>

    <div class="pk-report__select">
        <x-filament::input.wrapper>
            <x-filament::input.select wire:model.live="report">
                @foreach ($this->options as $value => $label)
                    <option value="{{ $value }}">{{ $label }}</option>
                @endforeach
            </x-filament::input.select>
        </x-filament::input.wrapper>
    </div>

    @if ($data === null)
        <x-filament::section>
            <p class="pk-report__muted">Brak raportu. Pierwszy powstanie w sobotę o 20:00.</p>
        </x-filament::section>
    @else
        <div class="pk-report">
            <x-filament::section :heading="$current['deal']->getLabel().': '.$current['label']" :description="$data['has_previous'] ? 'Zmiany względem poprzedniego zapisanego raportu.' : 'Brak wcześniejszego raportu do porównania.'">
                <div class="pk-report__stats">
                    <div>
                        <div class="pk-report__label">Oferty w tygodniu</div>
                        <div class="pk-report__value">{{ $data['totals']['active'] }}</div>
                    </div>
                    <div>
                        <div class="pk-report__label">Nowe</div>
                        <div class="pk-report__value">{{ $data['totals']['new'] }}</div>
                    </div>
                    <div>
                        <div class="pk-report__label">Mediana ceny</div>
                        <div class="pk-report__value">{{ Format::pln($data['totals']['median_price']) ?? '-' }}</div>
                        <div class="pk-report__change">{{ MarketReportPage::change($data['totals']['median_price_change']) }}</div>
                    </div>
                    <div>
                        <div class="pk-report__label">Mediana za m²</div>
                        <div class="pk-report__value">{{ Format::pln($data['totals']['median_price_per_m2']) ?? '-' }}</div>
                        <div class="pk-report__change">{{ MarketReportPage::change($data['totals']['median_price_per_m2_change']) }}</div>
                    </div>
                </div>
            </x-filament::section>

            @if ($data['searches'] !== [])
                <x-filament::section heading="Wyszukiwania" style="margin-top: 1.5rem">
                    <table>
                        <thead>
                            <tr><th>Wyszukiwanie</th><th class="num">Oferty</th><th class="num">Nowe</th><th class="num">Mediana ceny</th><th class="num">Za m²</th></tr>
                        </thead>
                        <tbody>
                            @foreach ($data['searches'] as $search)
                                <tr>
                                    <td>{{ $search['name'] }}</td>
                                    <td class="num">{{ $search['active'] }}</td>
                                    <td class="num">{{ $search['new'] }}</td>
                                    <td class="num">{{ Format::pln($search['median_price']) ?? '-' }} <span class="pk-report__muted">{{ MarketReportPage::change($search['median_price_change']) }}</span></td>
                                    <td class="num">{{ Format::pln($search['median_price_per_m2']) ?? '-' }} <span class="pk-report__muted">{{ MarketReportPage::change($search['median_price_per_m2_change']) }}</span></td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </x-filament::section>
            @endif

            @if ($transactions = $data['transactions'] ?? null)
                <x-filament::section heading="Ceny transakcyjne (RCN)" description="Prawdziwe ceny sprzedaży z aktów notarialnych. Rejestr ma kilka tygodni opóźnienia." style="margin-top: 1.5rem">
                    <div class="pk-report__stats">
                        <div>
                            <div class="pk-report__label">Oferty taniej niż transakcje</div>
                            <div class="pk-report__value">{{ $transactions['below'] }}</div>
                        </div>
                        <div>
                            <div class="pk-report__label">W granicach rynku / drożej</div>
                            <div class="pk-report__value">{{ $transactions['market'] }} / {{ $transactions['above'] }}</div>
                            <div class="pk-report__change">mediana różnicy {{ $transactions['median_diff_percent'] !== null ? ($transactions['median_diff_percent'] > 0 ? '+' : '').str_replace('.', ',', (string) $transactions['median_diff_percent']).'%' : '-' }}</div>
                        </div>
                        @foreach (['primary' => 'Rynek pierwotny', 'secondary' => 'Rynek wtórny'] as $market => $label)
                            <div>
                                <div class="pk-report__label">{{ $label }}, ostatnie 3 mies.</div>
                                <div class="pk-report__value">{{ Format::pln($transactions['last_3_months'][$market]['median_price_per_m2'] ?? null) ?? '-' }}</div>
                                <div class="pk-report__change">{{ ($transactions['last_3_months'][$market]['count'] ?? 0) }} transakcji, za m²</div>
                            </div>
                        @endforeach
                    </div>
                </x-filament::section>
            @endif

            <div class="pk-report__grid" style="margin-top: 1.5rem">
                <x-filament::section heading="Dzielnice" description="Dzielnice z co najmniej 3 ofertami.">
                    @if ($data['districts'] === [])
                        <p class="pk-report__muted">Za mało ofert z podaną dzielnicą.</p>
                    @else
                        <table>
                            <thead><tr><th>Dzielnica</th><th class="num">Oferty</th><th class="num">Mediana za m²</th></tr></thead>
                            <tbody>
                                @foreach ($data['districts'] as $district)
                                    <tr><td>{{ $district['name'] }}</td><td class="num">{{ $district['count'] }}</td><td class="num">{{ Format::pln($district['median_price_per_m2']) }}</td></tr>
                                @endforeach
                            </tbody>
                        </table>
                    @endif
                </x-filament::section>

                <x-filament::section heading="Pokoje">
                    @if ($data['rooms'] === [])
                        <p class="pk-report__muted">Brak danych o pokojach.</p>
                    @else
                        <table>
                            <thead><tr><th>Pokoje</th><th class="num">Oferty</th><th class="num">Mediana ceny</th></tr></thead>
                            <tbody>
                                @foreach ($data['rooms'] as $row)
                                    <tr><td>{{ $row['rooms'] }}</td><td class="num">{{ $row['count'] }}</td><td class="num">{{ Format::pln($row['median_price']) }}</td></tr>
                                @endforeach
                            </tbody>
                        </table>
                    @endif
                </x-filament::section>

                <x-filament::section heading="Obniżki cen" :description="$data['price_drops']['count'] > 0 ? $data['price_drops']['count'].' obniżek, mediana −'.str_replace('.', ',', (string) $data['price_drops']['median_percent']).'%' : null">
                    @if ($data['price_drops']['top'] === [])
                        <p class="pk-report__muted">Brak obniżek w tym tygodniu.</p>
                    @else
                        <table>
                            <tbody>
                                @foreach ($data['price_drops']['top'] as $drop)
                                    <tr>
                                        <td><a href="{{ route('filament.admin.resources.listings.view', $drop['id']) }}">{{ Str::limit($drop['title'], 60) }}</a></td>
                                        <td class="num">{{ Format::pln($drop['old_price']) }} → {{ Format::pln($drop['new_price']) }}</td>
                                        <td class="num">−{{ str_replace('.', ',', (string) $drop['percent']) }}%</td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    @endif
                </x-filament::section>

                <x-filament::section heading="Najlepsze nowe oferty" description="Najwyższa ocena AI wśród ofert z tego tygodnia.">
                    @if ($data['best_new'] === [])
                        <p class="pk-report__muted">Brak ocenionych nowych ofert.</p>
                    @else
                        <table>
                            <tbody>
                                @foreach ($data['best_new'] as $listing)
                                    <tr>
                                        <td><x-filament::badge :color="Format::scoreColor($listing['score'])" size="sm">{{ $listing['score'] }}/10</x-filament::badge></td>
                                        <td><a href="{{ route('filament.admin.resources.listings.view', $listing['id']) }}">{{ Str::limit($listing['title'], 60) }}</a><br><span class="pk-report__muted">{{ collect([Format::area($listing['area']), $listing['rooms'] ? $listing['rooms'].' pok.' : null, $listing['district']])->filter()->implode(' · ') }}</span></td>
                                        <td class="num">{{ Format::pln($listing['price']) }}</td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    @endif
                </x-filament::section>
            </div>
        </div>
    @endif
</x-filament-panels::page>
