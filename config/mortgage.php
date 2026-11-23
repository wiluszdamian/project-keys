<?php

/*
 * Rodzinny Kredyt Mieszkaniowy (ustawa z 1.10.2021, tekst jednolity Dz.U. 2026 poz. 1396) i stawki rynkowe.
 * BGK nie ma API, a POLSTR i WIBOR nie mają darmowego źródła do pobierania, więc stawki są tu
 * i w Ustawieniach panelu. Automatycznie pobieramy tylko stopę referencyjną NBP (XML na static.nbp.pl):
 * gdy zmieni się od daty stawek, panel podpowiada nowy wskaźnik i prosi o aktualizację.
 */
return [
    // Stawki rynkowe z 20.11.2026. Wskaźnik: POLSTR 1M stopa składana (od jesieni 2026 nowe kredyty zmienne w PKO BP i Erste).
    'rates_date' => env('MORTGAGE_RATES_DATE', '2026-11-20'),
    'index_name' => env('MORTGAGE_INDEX_NAME', 'POLSTR 1M'),
    'index_rate' => (float) env('MORTGAGE_INDEX_RATE', 3.49),
    // Stopa referencyjna NBP w dniu stawek, do wykrycia zmiany stóp.
    'nbp_rate_at_date' => (float) env('MORTGAGE_NBP_RATE_AT_DATE', 3.75),
    // RKM liczy się jak kredyt z 20% wkładu. Erste: 2,50% przed zniżkami, rynek przy 80% LTV 1,6 do 2,1%.
    'margin' => (float) env('MORTGAGE_MARGIN', 2.3),
    // Stałe przez 5 lat: rynek 5,8 do 6,6%, Erste RKM 7,07% przed zniżkami.
    'fixed_rate' => (float) env('MORTGAGE_FIXED_RATE', 6.2),
    'fixed_years' => 5,
    // Ubezpieczenie pomostowe: wyższa marża do wpisu hipoteki.
    'bridge_extra' => (float) env('MORTGAGE_BRIDGE_EXTRA', 1.0),
    'bridge_months' => (int) env('MORTGAGE_BRIDGE_MONTHS', 3),
    'valuation_fee' => 560,
    // Bufor na wzrost stóp w teście raty.
    'stress_extra' => 2.5,

    'nbp_rates_url' => 'https://static.nbp.pl/dane/stopy/stopy_procentowe.xml',

    // Zasady RKM (art. 3, 4a, 5, 7 ustawy).
    'rkm' => [
        'guarantee_share' => 0.20,
        'guarantee_max' => 100_000,
        'own_plus_guarantee_max' => 200_000,
        'guarantee_fee' => 0.01,
        'min_years' => 15,
        'max_years' => 35,
        'family_repayment_second' => 20_000,
        'family_repayment_third' => 60_000,
        'multiplier_secondary' => 1.3,
        'multiplier_primary' => 1.4,
        // Limity ceny za m² z tabeli BGK z 01.10.2026 (4. kwartał 2026), już z mnożnikiem.
        'limits_quarter' => '4. kwartał 2026',
        'limits' => [
            'rzeszow' => ['label' => 'Rzeszów', 'secondary' => 11_731.85, 'primary' => 12_634.30],
            'neighbour' => ['label' => 'gmina sąsiadująca z Rzeszowem', 'secondary' => 10_656.75, 'primary' => 11_476.50],
            'other' => ['label' => 'reszta podkarpackiego', 'secondary' => 9_581.65, 'primary' => 10_318.70],
        ],
        // Gminy sąsiadujące z Rzeszowem (wykaz BGK) i miejscowości z naszych wyszukiwań.
        'neighbour_places' => [
            'boguchwała', 'chmielnik', 'głogów małopolski', 'krasne', 'lubenia', 'świlcza', 'trzebownisko', 'tyczyn',
            'tajęcina', 'rudna mała',
        ],
    ],
];
