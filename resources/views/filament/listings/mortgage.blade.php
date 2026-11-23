{{--
    Symulacja Rodzinnego Kredytu Mieszkaniowego na stronie oferty sprzedaży.
    Stan: ['fixed' => wynik RkmSimulator, 'variable' => wynik RkmSimulator, 'url' => link do strony Kredyt].
--}}
@php
    use App\Services\Mortgage\RkmSimulator;

    $state = $getState();
    $fixed = $state['fixed'];
    $variable = $state['variable'];
    $money = fn ($value) => RkmSimulator::money($value);
    $pct = fn ($value) => number_format((float) $value, 2, ',', ' ').'%';
    $limit = $fixed['limit'];
    $variants = [
        ['label' => 'Stałe '.$pct(config('mortgage.fixed_rate')), 'r' => $fixed],
        ['label' => 'Zmienne '.$pct($variable['variable_rate']), 'r' => $variable],
    ];
@endphp

<x-dynamic-component :component="$getEntryWrapperView()" :entry="$entry">
<div class="pk-loan pk-loan__stack pk-loan__stack--tight">
    @foreach ($fixed['warnings'] as $warning)
        <div class="pk-loan__box pk-loan__box--warn">{{ $warning }}</div>
    @endforeach

    <div class="pk-loan__stats">
        <div>
            <div class="pk-loan__label">Rata (stałe przez 5 lat)</div>
            <div class="pk-loan__value">{{ $money($fixed['installment']) }}</div>
            <div class="pk-loan__note">na {{ $fixed['years'] }} lat</div>
        </div>
        <div>
            <div class="pk-loan__label">Kwota kredytu</div>
            <div class="pk-loan__value">{{ $money($fixed['loan']) }}</div>
            <div class="pk-loan__note">gwarancja BGK {{ $money($fixed['guarantee']) }}</div>
        </div>
        <div>
            <div class="pk-loan__label">Twój wkład</div>
            <div class="pk-loan__value">{{ $money($fixed['own']) }}</div>
            <div class="pk-loan__note">plus opłaty {{ $money($fixed['fees_total']) }}</div>
        </div>
        <div>
            <div class="pk-loan__label">Odsetki razem</div>
            <div class="pk-loan__value">{{ $money($fixed['schedule']['interest']) }}</div>
            <div class="pk-loan__note">przy dzisiejszych stopach</div>
        </div>
    </div>

    <div class="pk-scroll-x">
        <table class="pk-rows">
            <thead>
                <tr>
                    <th>Oprocentowanie</th>
                    <th class="num">Pierwsze {{ config('mortgage.bridge_months') }} mies.</th>
                    <th class="num">Rata</th>
                    <th class="num">Po 5 latach</th>
                    <th class="num">Stopy +{{ str_replace('.', ',', config('mortgage.stress_extra')) }} pp</th>
                    <th class="num">Odsetki razem</th>
                </tr>
            </thead>
            <tbody>
                @foreach ($variants as $variant)
                    @php($r = $variant['r'])
                    <tr>
                        <td>{{ $variant['label'] }}</td>
                        <td class="num">{{ $r['installment_bridge'] ? $money($r['installment_bridge']) : '-' }}</td>
                        <td class="num">{{ $money($r['installment']) }}</td>
                        <td class="num">{{ $money($r['installment_after_fixed'] ?? $r['installment']) }}</td>
                        <td class="num">{{ $money($r['stress_installment']) }}</td>
                        <td class="num">{{ $money($r['schedule']['interest']) }}</td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    </div>

    @if ($limit)
        <div @class(['pk-loan__box', 'pk-loan__box--ok' => $limit['ok'], 'pk-loan__box--bad' => ! $limit['ok']])>
            Cena za m² {{ $money($limit['price_per_m2']) }}, limit RKM {{ $money($limit['limit']) }} ({{ $limit['zone'] }}, {{ $limit['quarter'] }}).
            {{ $limit['ok'] ? 'Mieszkanie się mieści.' : 'Mieszkanie się nie mieści, bank nie udzieli na nie RKM.' }}
        </div>
    @else
        <div class="pk-loan__box pk-loan__box--warn">Oferta nie podaje metrażu, więc nie da się sprawdzić limitu ceny za m².</div>
    @endif

    <p class="pk-loan__text">
        Stałe przez 5 lat, potem zmienne. Zmienne to {{ config('mortgage.index_name') }} plus marża.
        Stawki z dnia {{ config('mortgage.rates_date') }}, z ubezpieczeniem pomostowym, bez prowizji, PCC i notariusza.
        <a href="{{ $state['url'] }}">Zmień parametry i policz spłatę rodzinną</a>
    </p>
</div>
</x-dynamic-component>
