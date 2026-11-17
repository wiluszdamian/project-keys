# Architektura

## Przepływ

```
Użytkownik -> API (zapis filtrów)
Scheduler (co N minut) -> RunSearchJob dla każdego aktywnego wyszukiwania
RunSearchJob -> ScrapePortalJob dla każdego portalu z wyszukiwania
ScrapePortalJob -> pobiera listę wyników -> zapisuje/aktualizuje listings -> nowe dopasowania do search_listing
Nowe dopasowanie -> AnalyzeListingJob (AI) -> listing_analyses
Po ocenie -> jeśli score >= próg -> SendListingNotificationJob (Telegram/Discord)
Pierwszy przebieg wyszukiwania lub dużo nowych naraz -> SendDigestJob (jedno zbiorcze podsumowanie)
```

## Usługi (Docker Compose)

| Usługa | Obraz | Rola | Port na zewnątrz |
|---|---|---|---|
| app | własny, PHP 8.5 FPM | Laravel (API) | brak |
| worker | ten sam co app | `php artisan queue:work` | brak |
| scheduler | ten sam co app | `php artisan schedule:work` | brak |
| postgres | postgres:17 | baza danych | brak |
| redis | redis:7 | kolejka, cache, locki | brak |
| caddy | caddy:2 | HTTPS, reverse proxy do app przez php_fastcgi | 80, 443 |

Zasady:

- Tylko `caddy` publikuje porty. Postgres i Redis nigdy nie publikują portów na zewnątrz (Docker omija ufw, więc opublikowany port jest dostępny z internetu mimo firewalla).
- Jeśli potrzebny dostęp do bazy z zewnątrz, to przez tunel SSH albo port przypięty do `127.0.0.1`.
- `app`, `worker`, `scheduler` używają jednego obrazu, różnią się komendą.
- Wolumeny: `pgdata`, `redisdata`, `caddy_data`, `caddy_config`.
- Healthchecki dla postgres i redis, `depends_on` z `condition: service_healthy`.
- Logi Dockera z rotacją (`max-size: 10m`, `max-file: 3`).

Bez osobnego kontenera z przeglądarką. Playwright dodajemy tylko, jeśli któryś portal nie da się czytać przez zwykłe HTTP (faza opcjonalna).

## Struktura kodu

```
app/
  Scrapers/
    Contracts/PortalScraper.php      # interfejs: buildSearchUrl(Search), fetchListings(Search): ListingData[]
    Data/ListingData.php             # DTO jednego ogłoszenia
    OtodomScraper.php
    OlxScraper.php
    ScraperRegistry.php              # mapowanie nazwa portalu -> klasa
    HttpFetcher.php                  # wspólny klient HTTP: user agent, opóźnienia, retry, obsługa 403/429
  Jobs/
    RunSearchJob.php
    ScrapePortalJob.php
    AnalyzeListingJob.php
    SendListingNotificationJob.php
    SendDigestJob.php
  Services/
    ListingUpserter.php              # zapis, deduplikacja, historia cen
    ListingMatcher.php               # dodatkowe filtrowanie po stronie aplikacji
    MarketStats.php                  # mediana ceny za m2 dla miasta/dzielnicy z naszej bazy
    ListingScorer.php                # wywołanie AI, walidacja odpowiedzi
  Notifications/
    NewListingNotification.php
    DigestNotification.php
  Models/ Search, Listing, SearchListing, ListingAnalysis, ListingPriceChange, ScrapeRun
  Http/Controllers/Api/ SearchController, ListingController
  Console/Commands/ SearchCreate, SearchRunNow, NotifyTest, MakeApiToken
```

## Ważne zasady działania

- Jeden przebieg wyszukiwania naraz: `RunSearchJob` z `WithoutOverlapping` (lock w Redis po `search_id`).
- Jeden portal naraz: osobny limit (rate limiter) na portal, żeby kilka wyszukiwań nie uderzało w ten sam portal równolegle.
- Kolejki: `scrape`, `ai`, `notify`. Worker obsługuje wszystkie, ale z priorytetem `notify,ai,scrape`.
