<?php

use App\Scrapers\OlxScraper;
use App\Scrapers\OtodomScraper;

return [
    // Portale, które można wybrać w wyszukiwaniu.
    'portals' => ['otodom', 'olx'],

    // Klasa scrapera dla każdego portalu.
    'classes' => [
        'otodom' => OtodomScraper::class,
        'olx' => OlxScraper::class,
    ],

    'interval_minutes' => (int) env('SCRAPE_INTERVAL_MINUTES', 20),
    'delay_ms' => (int) env('SCRAPER_DELAY_MS', 4000),
    // Losowy dodatek do przerwy między zapytaniami.
    'jitter_ms' => 2000,
    'max_pages' => (int) env('SCRAPER_MAX_PAGES', 3),
    'user_agent' => env('SCRAPER_USER_AGENT', 'Mozilla/5.0'),
    'timeout_seconds' => 30,
    // Ponowienia przy 5xx i timeoucie.
    'retries' => 2,
    'retry_sleep_seconds' => 30,
    // Jak długo portal jest wstrzymany po 403 lub 429.
    'block_hours' => 2,

    'otodom' => [
        'base_url' => 'https://www.otodom.pl',
        // Miasto (slug) -> lokalizacja w adresie Otodom. Sprawdzone na żywej stronie 2026-11-17.
        // Nowe miasto: otwórz wyniki na otodom.pl i skopiuj część adresu po /mieszkanie/.
        'locations' => [
            'krakow' => 'malopolskie/krakow/krakow/krakow',
            'warszawa' => 'mazowieckie/warszawa/warszawa/warszawa',
            'wroclaw' => 'dolnoslaskie/wroclaw/wroclaw/wroclaw',
            'gdansk' => 'pomorskie/gdansk/gdansk/gdansk',
            'poznan' => 'wielkopolskie/poznan/poznan/poznan',
            'lodz' => 'lodzkie/lodz/lodz/lodz',
            'rzeszow' => 'podkarpackie/rzeszow/rzeszow/rzeszow',
            'boguchwala' => 'podkarpackie/rzeszowski/boguchwala/boguchwala',
            'tajecina' => 'podkarpackie/rzeszowski/trzebownisko/tajecina',
            'rudna-mala' => 'podkarpackie/rzeszowski/glogow-malopolski/rudna-mala',
        ],
    ],

    'olx' => [
        'base_url' => 'https://www.olx.pl',
        // Tyle ogłoszeń zwraca endpoint na stronę (jak sama strona OLX).
        'per_page' => 40,
    ],

    // Ta sama oferta na dwóch portalach: to samo miasto i typ transakcji,
    // cena różna o mniej niż 2%, metraż o mniej niż 1 m2, widziana w ciągu 7 dni.
    'duplicates' => [
        'price_tolerance' => 0.02,
        'area_tolerance' => 1.0,
        'days' => 7,
    ],
];
