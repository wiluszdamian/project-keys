@php
    use App\Services\Mortgage\RkmSimulator;

    $r = $this->result;
    $money = fn ($value) => RkmSimulator::money($value);
    $pct = fn ($value) => number_format((float) $value, 2, ',', ' ').'%';
    $listing = $this->getListingRecord();
    $children = $r['with_children'];
@endphp

<x-filament-panels::page>
    <style>
        .pk-loan__stats { display: grid; grid-template-columns: repeat(2, minmax(0, 1fr)); gap: 1rem; }
        @media (min-width: 1024px) { .pk-loan__stats { grid-template-columns: repeat(4, minmax(0, 1fr)); } }
        .pk-loan__label { font-size: .8125rem; color: var(--gray-500); }
        .pk-loan__value { font-size: 1.375rem; font-weight: 600; color: var(--gray-950); margin-top: .25rem; font-variant-numeric: tabular-nums; }
        .dark .pk-loan__value { color: white; }
        .pk-loan__note { font-size: .8125rem; color: var(--gray-500); margin-top: .125rem; }
        .pk-loan__box { border-radius: .75rem; padding: .75rem 1rem; font-size: .875rem; line-height: 1.5; }
        .pk-loan__box--bad { background: var(--danger-50); color: var(--danger-700); }
        .pk-loan__box--warn { background: var(--warning-50); color: var(--warning-800); }
        .pk-loan__box--ok { background: var(--success-50); color: var(--success-700); }
        .dark .pk-loan__box--bad { background: rgb(239 68 68 / .12); color: var(--danger-300); }
        .dark .pk-loan__box--warn { background: rgb(245 158 11 / .12); color: var(--warning-300); }
        .dark .pk-loan__box--ok { background: rgb(34 197 94 / .12); color: var(--success-300); }
        .pk-loan__text { font-size: .875rem; line-height: 1.6; color: var(--gray-700); }
        .dark .pk-loan__text { color: var(--gray-300); }
        .pk-loan__text a { color: var(--primary-600); } .dark .pk-loan__text a { color: var(--primary-400); }
        .pk-loan .pk-rows td.num, .pk-loan .pk-rows th.num { text-align: right; font-variant-numeric: tabular-nums; }
        .pk-loan__stack > * + * { margin-top: 1.5rem; }
        .pk-loan__text p + p { margin-top: .75rem; }
    </style>

    @if ($listing)
        <p class="pk-loan__text">
            Dla oferty: <a href="{{ route('filament.admin.resources.listings.view', $listing) }}">{{ $listing->title }}</a>
        </p>
    @endif

    {{ $this->form }}

    <div class="pk-loan pk-loan__stack">
        @if ($this->ratesNotice)
            <div class="pk-loan__box pk-loan__box--warn">{{ $this->ratesNotice }}</div>
        @endif

        @foreach ($r['warnings'] as $warning)
            <div class="pk-loan__box pk-loan__box--bad">{{ $warning }}</div>
        @endforeach

        <x-filament::section heading="Wynik">
            <div class="pk-loan__stats">
                <div>
                    <div class="pk-loan__label">Rata miesięczna</div>
                    <div class="pk-loan__value">{{ $money($r['installment']) }}</div>
                    <div class="pk-loan__note">
                        @if ($r['fixed'])
                            przez 5 lat, {{ $pct($this->data['fixed_rate'] ?? 0) }}
                        @else
                            {{ $pct($r['variable_rate']) }} ({{ config('mortgage.index_name') }} + marża)
                        @endif
                    </div>
                </div>
                @if ($r['installment_bridge'])
                    <div>
                        <div class="pk-loan__label">Rata na start</div>
                        <div class="pk-loan__value">{{ $money($r['installment_bridge']) }}</div>
                        <div class="pk-loan__note">do wpisu hipoteki, ok. {{ config('mortgage.bridge_months') }} mies.</div>
                    </div>
                @endif
                @if ($r['fixed'])
                    <div>
                        <div class="pk-loan__label">Rata po 5 latach</div>
                        <div class="pk-loan__value">{{ $money($r['installment_after_fixed']) }}</div>
                        <div class="pk-loan__note">zmienne {{ $pct($r['variable_rate']) }} przy dzisiejszym {{ config('mortgage.index_name') }}</div>
                    </div>
                @endif
                <div>
                    <div class="pk-loan__label">Gdy stopy wzrosną o {{ str_replace('.', ',', config('mortgage.stress_extra')) }} pp</div>
                    <div class="pk-loan__value">{{ $money($r['stress_installment']) }}</div>
                    <div class="pk-loan__note">{{ $r['fixed'] ? 'rata po 5 latach' : 'rata od razu' }}</div>
                </div>
                <div>
                    <div class="pk-loan__label">Kwota kredytu</div>
                    <div class="pk-loan__value">{{ $money($r['loan']) }}</div>
                    <div class="pk-loan__note">na {{ $r['years'] }} lat</div>
                </div>
                <div>
                    <div class="pk-loan__label">Gwarancja BGK</div>
                    <div class="pk-loan__value">{{ $money($r['guarantee']) }}</div>
                    <div class="pk-loan__note">zamiast wkładu własnego</div>
                </div>
                <div>
                    <div class="pk-loan__label">Twój wkład</div>
                    <div class="pk-loan__value">{{ $money($r['own']) }}</div>
                    <div class="pk-loan__note">plus opłaty {{ $money($r['fees_total']) }}</div>
                </div>
                <div>
                    <div class="pk-loan__label">Odsetki razem</div>
                    <div class="pk-loan__value">{{ $money($r['schedule']['interest']) }}</div>
                    <div class="pk-loan__note">gdy stopy się nie zmienią</div>
                </div>
            </div>
        </x-filament::section>

        @if ($r['limit'])
            <div @class(['pk-loan__box', 'pk-loan__box--ok' => $r['limit']['ok'], 'pk-loan__box--bad' => ! $r['limit']['ok']])>
                Cena za m²: {{ $money($r['limit']['price_per_m2']) }}. Limit RKM ({{ $r['limit']['zone'] }}, {{ ($this->data['market'] ?? null) === 'primary' ? 'od dewelopera' : 'rynek wtórny' }}, {{ $r['limit']['quarter'] }}): {{ $money($r['limit']['limit']) }}.
                {{ $r['limit']['ok'] ? 'Mieszkanie się mieści.' : 'Mieszkanie się nie mieści.' }}
                Przy tej cenie mieszkanie musi mieć co najmniej {{ number_format($r['limit']['min_area'], 1, ',', '') }} m².
            </div>
        @else
            <div class="pk-loan__box pk-loan__box--warn">Wpisz metraż, żeby sprawdzić limit ceny za m².</div>
        @endif

        @if ($children)
            <x-filament::section heading="Ze spłatą rodzinną">
                <p class="pk-loan__text">
                    BGK spłaci {{ $money($r['family_repayments']) }} kapitału. Odsetki razem spadną z {{ $money($r['schedule']['interest']) }}
                    do {{ $money($children['interest']) }} (mniej o {{ $money($r['schedule']['interest'] - $children['interest']) }}).
                    Rata po spłacie: {{ $money(last($children['periods'])['installment']) }}.
                    Spłata przepada, gdy w pierwszych 3 latach nadpłacisz kredyt ponad część z gwarancją, a trzeba ją oddać, gdy w ciągu 5 lat sprzedasz albo wynajmiesz mieszkanie.
                </p>
            </x-filament::section>
        @endif

        <x-filament::section heading="Zmiany raty" collapsible>
            <div class="pk-scroll-x">
                <table class="pk-rows">
                    <thead>
                        <tr><th>Od miesiąca</th><th class="num">Oprocentowanie</th><th class="num">Rata</th><th>Dlaczego</th></tr>
                    </thead>
                    <tbody>
                        @foreach ($r['schedule']['periods'] as $period)
                            <tr>
                                <td>{{ $period['from'] }}</td>
                                <td class="num">{{ $pct($period['rate']) }}</td>
                                <td class="num">{{ $money($period['installment']) }}</td>
                                <td>
                                    @if ($period['from'] === 1)
                                        {{ $r['installment_bridge'] ? 'start z ubezpieczeniem pomostowym' : 'start' }}
                                    @elseif ($r['fixed'] && $period['from'] === config('mortgage.fixed_years') * 12 + 1)
                                        koniec okresu stałej stopy
                                    @else
                                        wpis hipoteki, koniec pomostowego
                                    @endif
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </x-filament::section>

        <x-filament::section heading="Harmonogram po latach" collapsible collapsed>
            <div class="pk-scroll-x">
                <table class="pk-rows">
                    <thead>
                        <tr><th>Rok</th><th class="num">Wpłacone</th><th class="num">Kapitał</th><th class="num">Odsetki</th><th class="num">Zostało</th></tr>
                    </thead>
                    <tbody>
                        @foreach ($r['schedule']['years'] as $year)
                            <tr>
                                <td>{{ $year['year'] }}</td>
                                <td class="num">{{ $money($year['paid']) }}</td>
                                <td class="num">{{ $money($year['capital']) }}</td>
                                <td class="num">{{ $money($year['interest']) }}</td>
                                <td class="num">{{ $money($year['balance']) }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </x-filament::section>

        <x-filament::section heading="Jak to liczę" collapsible collapsed>
            <div class="pk-loan__text">
                <p>
                    Rodzinny Kredyt Mieszkaniowy (ustawa z 1.10.2021, tekst jednolity Dz.U. 2026 poz. 1396) można dostać do końca 2030 r.
                    BGK gwarantuje brakujący wkład do 20% ceny, najwyżej {{ $money(config('mortgage.rkm.guarantee_max')) }}, za jednorazową opłatą 1% gwarancji.
                    Dlatego bez żadnego wkładu da się kupić mieszkanie za najwyżej 500 000 zł. Powyżej trzeba dołożyć różnicę (przy 550 000 zł to 10 000 zł).
                    Gwarancja kończy się, gdy spłacisz kapitał równy jej kwocie.
                </p>
                <p>
                    Warunki: nikt w gospodarstwie nie ma mieszkania ani domu (wyjątki dla rodzin z co najmniej dwojgiem dzieci), okres kredytu co najmniej 15 lat,
                    cena za m² w limicie BGK. Bank nie może wziąć za RKM więcej niż za zwykły kredyt z 20% wkładu.
                    Udzielają go teraz: PKO BP, Pekao, Alior i Erste.
                </p>
                <p>
                    Stawki z dnia {{ config('mortgage.rates_date') }}: {{ config('mortgage.index_name') }} {{ $pct(config('mortgage.index_rate')) }},
                    marża {{ $pct(config('mortgage.margin')) }}, stałe przez 5 lat {{ $pct(config('mortgage.fixed_rate')) }}.
                    Zmienisz je w Ustawieniach (zakładka Kredyt). Rata równa, liczona od nowa po każdej zmianie oprocentowania.
                    Bez podatku PCC, notariusza i ubezpieczeń. To symulacja: bank sprawdzi jeszcze zdolność kredytową i poda własną ofertę.
                </p>
            </div>
        </x-filament::section>
    </div>
</x-filament-panels::page>
