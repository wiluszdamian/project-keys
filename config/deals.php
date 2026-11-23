<?php

/*
 * Okazje: oferty wyraźnie tańsze niż rynek albo po dużej obniżce. Liczy App\Services\DealFinder.
 */
return [
    // Sprzedaż: o tyle procent taniej za m² niż mediana transakcji z RCN w okolicy (bez porównań przybliżonych do miasta).
    'below_transactions_percent' => (int) env('DEALS_BELOW_TRANSACTIONS_PERCENT', 10),
    // Cena niższa o tyle procent od najwyższej ceny tej oferty, jaką widzieliśmy.
    'price_drop_percent' => (int) env('DEALS_PRICE_DROP_PERCENT', 5),
    // O tyle procent taniej za m² niż mediana ofert o podobnym metrażu (±20%) z ostatnich 60 dni (dzielnica albo miasto). Przy wynajmie liczymy najem z czynszem.
    'below_market_percent' => (int) env('DEALS_BELOW_MARKET_PERCENT', 15),
    // Przy wynajmie ceny za m² bardziej się różnią (standard, wyposażenie), więc próg wyższy.
    'below_market_percent_rent' => (int) env('DEALS_BELOW_MARKET_PERCENT_RENT', 25),
    // Okazja tylko dla oferty, która pasuje do preferencji: najlepsza ocena AI co najmniej tyle (gdy oferta ma ocenę).
    'min_score' => (int) env('DEALS_MIN_SCORE', 6),
    // Oferta musi być widziana na portalu w ostatnich tylu dniach.
    'active_days' => 3,
    // Gdy w jednym sprawdzeniu nowych okazji jest więcej, przychodzi jedno podsumowanie zamiast osobnych wiadomości.
    'max_single_messages' => 3,
];
