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

Stan na 2026-11-17 (fazy 0 do 6):

```
app/
  Ai/Agents/ListingScoringAgent.php  # prompt i schemat odpowiedzi (laravel/ai)
  Scrapers/
    Contracts/PortalScraper.php      # buildSearchUrl, fetchListings, parse
    Contracts/EnrichesListings.php   # pobranie strony oferty, gdy lista nie ma opisu (Otodom)
    Data/ListingData.php, ScrapeResult.php
    OtodomScraper.php, OlxScraper.php
    ScraperRegistry.php              # portal -> klasa (config/scrapers.php)
    HttpFetcher.php                  # przerwy, jedno zapytanie naraz na portal, retry, 403/429 -> pauza 2 h, HTTP/2
  Jobs/
    RunSearchJob.php                 # batch ScrapePortalJob dla portali wyszukiwania
    ScrapePortalJob.php              # pobranie, filtr, zapis, scrape_runs
    NotifyRunResultsJob.php          # po przebiegu: łańcuch AnalyzeListingJob, potem wysyłka
    AnalyzeListingJob.php            # ocena AI jednej oferty (kolejka ai, limit na minutę)
    SendListingNotificationJob.php, SendDigestJob.php
  Events/ ListingMatched, ListingPriceChanged, PortalBlockedRepeatedly
  Listeners/ SendPriceDropNotification, SendPortalBlockedAlert
  Services/
    ListingUpserter.php              # zapis, historia cen, duplikaty, nie kasuje danych ze strony oferty
    DuplicateDetector.php            # ta sama oferta na dwóch portalach
    ListingMatcher.php               # filtrowanie po pobraniu (dzielnice, zakresy)
    MarketStats.php                  # mediana ceny za m2 (dzielnica albo miasto, 60 dni)
    ListingScorer.php                # prompt, walidacja odpowiedzi, zapis oceny
  Notifications/ Notifier.php, Discord/DiscordWebhook.php, Discord/DiscordMessages.php
  Models/ Search, Listing, SearchListing, ListingAnalysis, ListingPriceChange, ScrapeRun, User
  Http/Controllers/Api/ SearchController, ListingController, ScrapeRunController, HealthController
  Console/Commands/ SearchCreate, SearchList, SearchPause, SearchResume, SearchRunNow, SearchDispatchDue,
                    MakeApiToken, ScraperProbe, NotifyTest, AiUsage, AlertsCheck
scripts/test.sh                      # testy na serwerze (Postgres mieszkania_test)
```

Przepływ w praktyce: scheduler co minutę `search:dispatch-due` -> `RunSearchJob` -> batch `ScrapePortalJob` -> po batchu `NotifyRunResultsJob` -> łańcuch `AnalyzeListingJob` -> `NotifyRunResultsJob` (analyzed) -> Discord.

## Ważne zasady działania

- Jeden przebieg wyszukiwania naraz: `RunSearchJob` z `WithoutOverlapping` (lock w Redis po `search_id`).
- Jeden portal naraz: osobny limit (rate limiter) na portal, żeby kilka wyszukiwań nie uderzało w ten sam portal równolegle.
- Kolejki: `scrape`, `ai`, `notify`. Worker obsługuje wszystkie, ale z priorytetem `notify,ai,scrape`.
