@php
    use App\Enums\DealType;
    use App\Enums\ListingStatus;
    use App\Filament\Pages\MarketReportPage;
    use App\Services\Products\ProductAdvisor;
    use App\Filament\Support\Format;
    use App\Services\TimeOnMarket;
    use Illuminate\Support\Str;

    $current = $this->current;
    $data = $current['data'] ?? null;
    $rent = ($current['deal'] ?? null) === DealType::Rent;
@endphp

<x-filament-panels::page>
    <style>
        .pk-report__stats { display: grid; grid-template-columns: repeat(2, minmax(0, 1fr)); gap: 1rem; }
        @media (min-width: 1024px) { .pk-report__stats { grid-template-columns: repeat(auto-fit, minmax(10rem, 1fr)); } }
        .pk-report__summary p { color: var(--gray-950); line-height: 1.5; } .dark .pk-report__summary p { color: var(--gray-100); }
        .pk-report__summary ul { list-style: disc; padding-left: 1.25rem; margin-top: .5rem; color: var(--gray-700); } .dark .pk-report__summary ul { color: var(--gray-300); }
        .pk-report__trend { display: grid; grid-template-columns: 1fr; gap: 1rem; }
        @media (min-width: 768px) { .pk-report__trend { grid-template-columns: 1fr 1fr; } }
        .pk-report__good { color: var(--success-600); } .dark .pk-report__good { color: var(--success-400); }
        .pk-report__bad { color: var(--danger-600); } .dark .pk-report__bad { color: var(--danger-400); }
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
        /* Telefon: szeroka tabela przewija się w bok zamiast wychodzić poza kartę. */
        @media (max-width: 767px) {
            .pk-report table { display: block; overflow-x: auto; -webkit-overflow-scrolling: touch; }
            .pk-report td:first-child { min-width: 8rem; }
        }
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
            @if ($summary = $data['ai_summary'] ?? null)
                <x-filament::section heading="Podsumowanie" icon="heroicon-o-sparkles" style="margin-bottom: 1.5rem">
                    <div class="pk-report__summary">
                        <p>{{ $summary['text'] }}</p>
                        @if ($summary['points'] !== [])
                            <ul>
                                @foreach ($summary['points'] as $point)
                                    <li>{{ $point }}</li>
                                @endforeach
                            </ul>
                        @endif
                    </div>
                </x-filament::section>
            @elseif ($current['live'])
                <p class="pk-report__muted" style="margin-bottom: 1rem">Podsumowanie AI powstaje przy zapisie raportu w sobotę, w podglądzie na żywo go nie ma.</p>
            @endif

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
                    @if ($rent && isset($data['totals']['median_total']))
                        <div>
                            <div class="pk-report__label">Mediana z czynszem adm.</div>
                            <div class="pk-report__value">{{ Format::pln($data['totals']['median_total']) ?? '-' }}</div>
                            <div class="pk-report__change">{{ MarketReportPage::change($data['totals']['median_total_change'] ?? null) ?? ($data['totals']['total_known'].' ofert podaje czynsz') }}</div>
                        </div>
                    @endif
                    @if ($supply = $data['supply'] ?? null)
                        <div>
                            <div class="pk-report__label">Podaż: nowe / zniknęły</div>
                            <div class="pk-report__value">{{ $supply['new'] }} / {{ $supply['gone'] }}</div>
                            <div class="pk-report__change">bilans {{ $supply['net'] > 0 ? '+' : '' }}{{ $supply['net'] }}@if ($supply['active_change'] !== null), ofert {{ MarketReportPage::change($supply['active_change']) }}@endif</div>
                        </div>
                    @endif
                </div>
            </x-filament::section>

            @if (count($data['trend'] ?? []) >= 2)
                <x-filament::section heading="Trend" :description="'Ostatnie '.count($data['trend']).' tygodnie, z ofert w bazie.'" style="margin-top: 1.5rem">
                    <div class="pk-report__trend">
                        <div>
                            <div class="pk-report__label">Mediana {{ $rent ? 'najmu' : 'za m²' }}</div>
                            {{ MarketReportPage::sparkline(array_column($data['trend'], $rent ? 'median_price' : 'median_price_per_m2')) }}
                        </div>
                        <div>
                            <div class="pk-report__label">Liczba ofert</div>
                            {{ MarketReportPage::sparkline(array_column($data['trend'], 'active')) }}
                        </div>
                    </div>
                    <table style="margin-top: .75rem">
                        <thead><tr><th>Tydzień do</th><th class="num">Oferty</th><th class="num">Nowe</th><th class="num">Mediana ceny</th><th class="num">{{ $rent ? 'Z czynszem' : 'Za m²' }}</th></tr></thead>
                        <tbody>
                            @foreach (array_reverse($data['trend']) as $week)
                                <tr>
                                    <td>{{ $week['label'] }}</td>
                                    <td class="num">{{ $week['active'] }}</td>
                                    <td class="num">{{ $week['new'] }}</td>
                                    <td class="num">{{ Format::pln($week['median_price']) ?? '-' }}</td>
                                    <td class="num">{{ Format::pln($rent ? ($week['median_total'] ?? null) : $week['median_price_per_m2']) ?? '-' }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </x-filament::section>
            @endif

            @if (($data['your_list'] ?? []) !== [])
                <x-filament::section heading="Twoja lista" description="Oferty ze statusem ulubiona, kontakt i oglądanie." style="margin-top: 1.5rem">
                    <table>
                        <tbody>
                            @foreach ($data['your_list'] as $item)
                                <tr>
                                    <td><x-filament::badge :color="ListingStatus::from($item['status'])->getColor()" size="sm">{{ ListingStatus::from($item['status'])->getLabel() }}</x-filament::badge></td>
                                    <td>
                                        <a href="{{ route('filament.admin.resources.listings.view', $item['id']) }}">{{ Str::limit($item['title'], 70) }}</a>
                                        <br><span class="pk-report__muted">{{ collect([
                                            $item['viewing_at'] ? 'oglądanie '.\Illuminate\Support\Carbon::parse($item['viewing_at'])->timezone(config('app.timezone'))->format('d.m H:i') : null,
                                            $item['gone_this_week'] ? 'zdjęta z portali w tym tygodniu' : ($item['gone'] ? 'zdjęta z portali' : null),
                                            $item['long'],
                                        ])->filter()->implode(' · ') }}</span>
                                    </td>
                                    <td class="num">
                                        {{ Format::pln($item['price']) }}
                                        @if ($item['old_price'])
                                            <br><span class="{{ $item['price'] < $item['old_price'] ? 'pk-report__good' : 'pk-report__bad' }}">było {{ Format::pln($item['old_price']) }}</span>
                                        @endif
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </x-filament::section>
            @endif

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

                @if ($timing = $data['time_on_market'] ?? null)
                    <x-filament::section heading="Jak szybko schodzą oferty" :description="$timing['gone'] > 0 ? 'Zdjęte w tym tygodniu: '.$timing['gone'].($timing['median_days'] !== null ? ', mediana '.TimeOnMarket::days($timing['median_days']).' na rynku' : '').'.' : 'W tym tygodniu nic nie zeszło z portali.'">
                        @if ($timing['by_rooms'] !== [])
                            <table>
                                <thead><tr><th>Pokoje</th><th class="num">Zdjęte</th><th class="num">Mediana dni</th></tr></thead>
                                <tbody>
                                    @foreach ($timing['by_rooms'] as $row)
                                        <tr><td>{{ $row['rooms'] }}</td><td class="num">{{ $row['count'] }}</td><td class="num">{{ $row['median_days'] }}</td></tr>
                                    @endforeach
                                </tbody>
                            </table>
                        @endif
                        @if ($timing['longest'] !== [])
                            <p class="pk-report__muted">Wiszą najdłużej (jest pole do negocjacji):</p>
                            <table>
                                <tbody>
                                    @foreach ($timing['longest'] as $row)
                                        <tr>
                                            <td><a href="{{ route('filament.admin.resources.listings.view', $row['id']) }}">{{ Str::limit($row['title'], 60) }}</a></td>
                                            <td class="num">{{ Format::pln($row['price']) }}</td>
                                            <td class="num">{{ $row['known'] ? '' : 'min. ' }}{{ TimeOnMarket::days($row['days']) }}</td>
                                        </tr>
                                    @endforeach
                                </tbody>
                            </table>
                        @endif
                    </x-filament::section>
                @endif

                @if (($deals = $data['deals'] ?? null) && $deals['count'] > 0)
                    <x-filament::section heading="Okazje tygodnia" :description="$deals['count'].' '.($deals['count'] === 1 ? 'nowa okazja' : 'nowych okazji').' w tym tygodniu.'">
                        <table>
                            <tbody>
                                @foreach ($deals['top'] as $deal)
                                    <tr>
                                        <td>
                                            @if ($deal['score'] !== null)<x-filament::badge :color="Format::scoreColor($deal['score'])" size="sm">{{ $deal['score'] }}/10</x-filament::badge>@endif
                                        </td>
                                        <td><a href="{{ route('filament.admin.resources.listings.view', $deal['id']) }}">{{ Str::limit($deal['title'], 60) }}</a><br><span class="pk-report__muted">{{ $deal['reasons'] }}</span></td>
                                        <td class="num">{{ Format::pln($deal['price']) }}</td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </x-filament::section>
                @endif

                @if (($products = $data['products'] ?? []) !== [])
                    <x-filament::section heading="Monitor cen" description="Najlepsza cena teraz i tydzień temu.">
                        <table>
                            <tbody>
                                @foreach ($products as $product)
                                    <tr>
                                        <td><a href="{{ route('filament.admin.resources.products.view', $product['id']) }}">{{ Str::limit($product['name'], 60) }}</a><br><span class="pk-report__muted">{{ collect([
                                            $product['shop'],
                                            $product['target_reached'] ? 'cena docelowa osiągnięta' : null,
                                            $product['verdict'] ? 'AI: '.(ProductAdvisor::VERDICTS[$product['verdict']] ?? $product['verdict']).' '.$product['score'].'/10' : null,
                                        ])->filter()->implode(' · ') }}</span></td>
                                        <td class="num">{{ Format::pln($product['best_price']) ?? 'niedostępny' }}
                                            @if ($product['change_percent'] !== null && abs($product['change_percent']) >= 0.1)
                                                <br><span class="{{ $product['change_percent'] < 0 ? 'pk-report__good' : 'pk-report__bad' }}">{{ MarketReportPage::change($product['change_percent']) }}</span>
                                            @endif
                                        </td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </x-filament::section>
                @endif

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
