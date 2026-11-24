<?php

use App\Scrapers\AdresowoScraper;
use App\Scrapers\DomiportaScraper;
use App\Scrapers\GratkaScraper;
use App\Scrapers\MorizonScraper;
use App\Scrapers\NieruchomosciOnlineScraper;
use App\Scrapers\OlxScraper;
use App\Scrapers\OtodomScraper;
use App\Scrapers\PierwszePietroScraper;
use App\Scrapers\RzeszowiakScraper;
use App\Scrapers\RzeszowskieNieruchomosciScraper;
use App\Scrapers\StoprocentScraper;

return [
    // Portale, które można wybrać w wyszukiwaniu.
    'portals' => ['otodom', 'olx', 'rzeszowiak', 'morizon', 'gratka', 'nieruchomosci_online', 'domiporta', 'adresowo', 'stoprocent', 'rzeszowskie', 'pierwsze_pietro'],

    // Klasa scrapera dla każdego portalu.
    'classes' => [
        'otodom' => OtodomScraper::class,
        'olx' => OlxScraper::class,
        'rzeszowiak' => RzeszowiakScraper::class,
        'morizon' => MorizonScraper::class,
        'gratka' => GratkaScraper::class,
        'nieruchomosci_online' => NieruchomosciOnlineScraper::class,
        'domiporta' => DomiportaScraper::class,
        'adresowo' => AdresowoScraper::class,
        'stoprocent' => StoprocentScraper::class,
        'rzeszowskie' => RzeszowskieNieruchomosciScraper::class,
        'pierwsze_pietro' => PierwszePietroScraper::class,
    ],

    // Niższa cena przy sprzedaży to ogłoszenie najmu w złej kategorii.
    'min_sale_price' => 50_000,

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

    // Sprawdzanie, czy ogłoszenie wciąż jest na portalu (listings:check-active).
    'availability' => [
        // Oferta widziana w wynikach w tym czasie jest aktywna, nie pobieramy jej strony.
        'unseen_hours' => 6,
        // Aktywną ofertę sprawdzamy ponownie po tylu godzinach.
        'recheck_hours' => 24,
        // Ile stron ogłoszeń na jeden przebieg (co 30 minut).
        'per_run' => 120,
        // Komunikat na stronie zdjętego ogłoszenia. Sprawdzone na żywych stronach 2026-11-24.
        // OLX i Otodom odpowiadają 410, stoprocent i adresowo 404, 1pietro, rzeszowskie i Domiporta
        // przekierowują na stronę główną albo listę ofert. To łapie ListingAvailability bez wzorców.
        'markers' => [
            'otodom' => '/<title>Archiwalne:|"status":"removed_by_user"/',
            'domiporta' => '/- Archiwum Domiporta\.pl|<section class="archive">/',
            'morizon' => '/To ogłoszenie nie jest już dostępne/u',
            'nieruchomosci_online' => '/Ogłoszenie archiwalne|<title>Strona błędu 404/u',
            'adresowo' => '/Ogłoszenie usunięte/u',
            // Strona w ISO-8859-2, dlatego "ł" jako dowolny znak (bajt).
            'rzeszowiak' => '/Og.{1,2}oszenie wygas.{1,2}o/',
            // OLX: bez wzorca, bo teksty "nie jest już dostępne" są też w kodzie aktywnych stron.
        ],
    ],

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

    'rzeszowiak' => [
        'base_url' => 'https://www.rzeszowiak.pl',
        // Portal rzeszowski: ogłoszenie bez nazwy miejscowości liczymy jako Rzeszów,
        // chyba że wspomina jedną z tych miejscowości.
        'home_city' => 'rzeszow',
        'home_city_name' => 'Rzeszów',
        'other_places' => [
            'Przemyśl', 'Krosno', 'Jasło', 'Dębica', 'Łańcut', 'Tyczyn', 'Boguchwała', 'Głogów Małopolski',
            'Trzebownisko', 'Krasne', 'Świlcza', 'Strzyżów', 'Ropczyce', 'Sokołów Małopolski', 'Kolbuszowa',
            'Leżajsk', 'Mielec', 'Sanok', 'Stalowa Wola', 'Tarnobrzeg', 'Jarosław', 'Tajęcina', 'Rudna Mała',
            'Zaczernie', 'Jasionka', 'Malawa', 'Racławówka', 'Lubenia', 'Błędowa', 'Kraków', 'Warszawa', 'Lublin',
        ],
    ],

    // Biuro Stoprocent Nieruchomości (Rzeszów). Lista z API WordPressa, dane ze strony ogłoszenia.
    'stoprocent' => [
        'base_url' => 'https://stoprocent-nieruchomosci.pl',
        'per_page' => 100,
        // Ile stron API (po 100 ofert wszystkich rodzajów, od najnowszych) najwyżej czytać. Biuro ma ok. 600 ofert,
        // a dodaje kilkanaście dziennie, więc czytamy wszystkie (zapytania do API są lekkie).
        'api_pages' => 10,
        // Najwięcej nowych stron ogłoszeń na jeden przebieg. Reszta w kolejnych przebiegach.
        'max_new_offers' => 40,
    ],

    // Biuro Rzeszowskie Nieruchomości (Rzeszów). Zwykły HTML, 12 ofert na stronę.
    'rzeszowskie' => [
        'base_url' => 'https://rzeszowskie-nieruchomosci.pl',
    ],

    // Biuro Pierwsze Piętro (Rzeszów, 1pietro.net). Zwykły HTML, 18 ofert na stronę.
    'pierwsze_pietro' => [
        'base_url' => 'https://1pietro.net',
    ],

    'domiporta' => [
        'base_url' => 'https://www.domiporta.pl',
        // Miasto (slug) -> lokalizacja w adresie (województwo/miejscowość). Sprawdzone 2026-11-18.
        'locations' => [
            'rzeszow' => 'podkarpackie/rzeszow',
            'boguchwala' => 'podkarpackie/boguchwala',
            'tajecina' => 'podkarpackie/tajecina',
            'rudna-mala' => 'podkarpackie/rudna-mala',
        ],
    ],

    // Morizon i Gratka mają te same oferty z tym samym id (raw.network_id), duplikaty łączymy dokładnie.
    'morizon' => [
        'base_url' => 'https://www.morizon.pl',
        // Miasto (slug) -> ścieżka lokalizacji. Sprawdzone 2026-11-18.
        'locations' => [
            'rzeszow' => 'rzeszow',
            'boguchwala' => 'rzeszowski/miasto-boguchwala',
            'tajecina' => 'rzeszowski/gmina-trzebownisko/tajecina',
            'rudna-mala' => 'rzeszowski/gmina-glogow-malopolski/rudna-mala',
        ],
    ],

    'gratka' => [
        'base_url' => 'https://gratka.pl',
        'locations' => [
            'rzeszow' => 'rzeszow',
            'boguchwala' => 'boguchwala',
            'tajecina' => 'tajecina',
            'rudna-mala' => 'rudna-mala-37382',
        ],
    ],

    'nieruchomosci_online' => [
        'base_url' => 'https://www.nieruchomosci-online.pl',
    ],

    'adresowo' => [
        'base_url' => 'https://adresowo.pl',
        // Miasto (slug) -> część adresu. Niektóre mają dopisek (boguchwala-o). Sprawdzone 2026-11-18.
        'locations' => [
            'rzeszow' => 'rzeszow',
            'boguchwala' => 'boguchwala-o',
            'tajecina' => 'tajecina',
            'rudna-mala' => 'rudna-mala',
        ],
    ],

    // Ta sama oferta na dwóch portalach: to samo miasto i typ transakcji,
    // cena różna o mniej niż 2%, metraż o mniej niż 1 m2, widziana w ciągu 7 dni.
    'duplicates' => [
        'price_tolerance' => 0.02,
        'area_tolerance' => 1.0,
        'days' => 7,
    ],
    // Jak długo oferty wiszą (TimeOnMarket). "Wisi długo" od 75. percentyla zdjętych podobnych ofert,
    // ale nie mniej niż min_long_days. Gdy zdjętych jest mniej niż min_sample, próg z long_days.
    'time_on_market' => [
        'long_days' => ['rent' => (int) env('LONG_ON_MARKET_RENT_DAYS', 30), 'sale' => (int) env('LONG_ON_MARKET_SALE_DAYS', 90)],
        'min_long_days' => 14,
        'min_sample' => 5,
        'lookback_days' => 120,
    ],
];
