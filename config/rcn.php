<?php

return [
    // Ceny transakcyjne z Rejestru Cen Nieruchomości (GUGiK, bezpłatne od 13.02.2026).
    'enabled' => (bool) env('RCN_ENABLED', true),

    // Usługa WFS z transakcjami (warstwa ms:lokale). Współrzędne w EPSG:2180 (PUWG 1992).
    'wfs_url' => env('RCN_WFS_URL', 'https://mapy.geoportal.gov.pl/wss/service/rcn'),

    // Powiaty (TERYT) do pobrania: 1863 Rzeszów, 1816 powiat rzeszowski. Po przecinku.
    'counties' => env('RCN_COUNTIES', '1863,1816'),

    // Ile miesięcy wstecz pobierać i porównywać.
    'import_months' => 36,
    'compare_months' => 24,

    // Porównanie: promienie od najmniejszego, dopóki nie ma tylu transakcji.
    'radii_m' => [800, 1500, 3000],
    'min_comparables' => 8,
    // Metraż transakcji w granicach ±tylu procent metrażu oferty.
    'area_tolerance_percent' => 30,
    // Różnica do tylu procent to "w granicach rynku". Ceny ofertowe są zwykle kilka procent wyżej niż transakcyjne.
    'market_tolerance_percent' => 10,

    // Sensowny zakres ceny za m2. Poza nim zwykle błąd w rejestrze albo transakcja z czymś więcej niż mieszkanie.
    'min_price_per_m2' => 2500,
    'max_price_per_m2' => 30000,

    'page_size' => 500,
    'timeout' => 180,
];
