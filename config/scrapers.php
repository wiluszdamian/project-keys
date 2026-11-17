<?php

return [
    // Portale, które można wybrać w wyszukiwaniu.
    'portals' => ['otodom', 'olx'],

    // Klasa scrapera dla każdego portalu.
    'classes' => [
        'otodom' => App\Scrapers\OtodomScraper::class,
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
        ],
    ],
];
