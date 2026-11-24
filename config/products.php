<?php

// Monitor cen produktów (sekcja "Produkty" w panelu).
return [
    'enabled' => (bool) env('PRODUCTS_ENABLED', true),

    // Nie pobieramy stron z sieci lokalnej serwera (localhost, 10.x, 192.168.x). Wyłączone tylko w testach.
    'block_private_hosts' => (bool) env('PRODUCTS_BLOCK_PRIVATE_HOSTS', true),

    // Co ile godzin sprawdzać cenę, gdy produkt nie ma własnego ustawienia.
    'check_every_hours' => (int) env('PRODUCTS_CHECK_EVERY_HOURS', 3),

    // Po tylu błędach z rzędu przy jednej ofercie idzie alert.
    'alert_after_failures' => 3,

    // Powiadomienia (Discord i panel): obniżki, cena docelowa, dostępność, tańszy sklep.
    'notify' => (bool) env('PRODUCTS_NOTIFY', true),

    // AI: odczyt ceny, gdy sklep jej nie podaje w danych strony, dopasowanie ofert z innych sklepów i ocena ceny.
    'ai' => (bool) env('PRODUCTS_AI', true),
    // Ile znaków treści strony dostaje AI przy odczycie ceny.
    'ai_page_chars' => 6000,
    // Ocena AI najwyżej raz na tyle godzin (chyba że zmieni się cena).
    'advice_every_hours' => 24,

    // Darmowe szukanie w innych sklepach: lista sklepów (Zakupy → Sklepy) i ich mapy stron (sitemap).
    'shops' => [
        // Mapy stron pobieramy od nowa co tyle dni.
        'sync_every_days' => 7,
        // Najwyżej tyle adresów z jednego sklepu i tyle map podrzędnych.
        'max_urls' => 200_000,
        'max_sitemaps' => 40,
        // Ile nowych lub starych sklepów odświeżyć przy jednym szukaniu (reszta w tle co tydzień).
        'sync_per_search' => 4,
        // Ile stron produktu sprawdzić z jednego sklepu.
        'candidates_per_shop' => 3,
    ],

    // Dodatkowo (opcjonalnie) cały internet przez Brave Search API (klucz z api-dashboard.search.brave.com).
    'search' => [
        'brave_key' => env('BRAVE_SEARCH_API_KEY'),
        // Automatycznie co tyle dni dla aktywnych produktów (0 = tylko ręcznie).
        'every_days' => (int) env('PRODUCTS_SEARCH_EVERY_DAYS', 3),
        // Ile stron z wyników sprawdzić za jednym razem.
        'max_candidates' => 15,
        // Strony, które nie są sklepami albo blokują roboty.
        'skip_domains' => [
            'ceneo.pl', 'allegro.pl', 'google.com', 'google.pl', 'youtube.com', 'facebook.com', 'instagram.com',
            'pinterest.com', 'tiktok.com', 'reddit.com', 'wikipedia.org', 'skapiec.pl', 'idealo.pl', 'opineo.pl',
            'olx.pl', 'amazon.com', 'x.com', 'twitter.com',
        ],
    ],
];
