# Roadmap

Każda faza kończy się: testami, commitem, aktualizacją STATUS.md i zatrzymaniem.

## Faza 0: przygotowanie serwera

Status: ✅ gotowe (2026-11-17)

- [x] Kroki 1 i 2 z DEPLOYMENT.md.
- [x] Zapisz w STATUS.md parametry serwera i wersje (Docker, Compose).

Gotowe gdy: logowanie tylko kluczem jako `deploy`, ufw aktywny (22, 80, 443), `docker run hello-world` działa.

## Faza 1: szkielet aplikacji

Status: ✅ gotowe (2026-11-17)

- [x] Najnowszy stabilny Laravel (**SPRAWDŹ** wersję), PHP 8.5. Laravel 13.43, PHP 8.5.12.
- [x] Dockerfile i docker-compose.yml według ARCHITECTURE.md.
- [x] Pest, Laravel Pint, `.gitignore` z `.env`.
- [x] Endpoint `/api/health`.

Gotowe gdy: `docker compose up -d` stawia wszystkie usługi, `/api/health` zwraca ok przez Caddy (HTTPS lub tunel), testy przechodzą.

## Faza 2: model danych i API

Status: ✅ gotowe (2026-11-17)

- [x] Migracje i modele z DATA_MODEL.md.
- [x] Sanctum, komenda `api:token`.
- [x] Endpointy z API.md (bez uruchamiania przebiegów, to w fazie 5).
- [x] Komendy `search:create`, `search:list`. Dodatkowo `search:pause` i `search:resume`.

Gotowe gdy: można utworzyć, zmienić i usunąć wyszukiwanie przez API i komendą, walidacja działa, testy feature dla API.

## Faza 3: scraper Otodom

Status: ✅ gotowe (2026-11-17)

- [x] `HttpFetcher` z zasadami z SCRAPERS.md (opóźnienia, 403/429, retry).
- [x] Interfejs `PortalScraper`, `ListingData`, `OtodomScraper`, `ListingUpserter`.
- [x] Fixture i testy, komenda `scraper:probe`.

Gotowe gdy: `scraper:probe otodom {id}` wypisuje prawdziwe oferty zgodne z filtrami, testy na fixture przechodzą.

## Faza 4: scraper OLX

Status: ✅ gotowe (2026-11-17)

- [x] `OlxScraper`, deduplikacja między portalami.

Gotowe gdy: jak w fazie 3 dla OLX, a ta sama oferta z obu portali zapisuje się jako duplikat.

## Faza 5: harmonogram i kolejka

Status: ✅ gotowe (2026-11-17), obserwacja przez godzinę w toku

- [x] `RunSearchJob`, `ScrapePortalJob`, `scrape_runs`, locki, rate limiter na portal, wstrzymywanie portalu po blokadzie.
- [x] Scheduler co `SCRAPE_INTERVAL_MINUTES`.
- [x] `search:run`, endpoint `POST /searches/{id}/run`.

Gotowe gdy: przez godzinę system sam sprawdza portale, w `scrape_runs` są wpisy, nowe oferty pojawiają się w bazie, brak duplikatów.

## Faza 6: ocena AI

Status: ✅ gotowe (2026-11-17)

- [x] Konfiguracja modułu AI Laravela z OpenCode Go (wszystkie **SPRAWDŹ** z AI_SCORING.md).
- [x] `MarketStats`, `ListingScorer`, `AnalyzeListingJob`, komenda `ai:usage`.
- [x] Dodatkowo (prośba właściciela): pełny koszt miesięczny (najem + czynsz + opłaty z opisu) i oceny cząstkowe: koszt, lokalizacja, standard, dopasowanie.
- [x] Jedno prawdziwe wywołanie działa (po włączeniu regionu Global w OpenCode).

Gotowe gdy: nowe oferty dostają ocenę w `listing_analyses`, niepoprawna odpowiedź modelu jest obsłużona, testy z mockiem AI przechodzą, jedno prawdziwe wywołanie działa.

## Faza 7: powiadomienia

Status: w trakcie (Discord zrobiony wcześniej, na prośbę właściciela)

- [ ] Discord i Telegram według NOTIFICATIONS.md, digest, obniżki cen, alerty systemowe.
  - [x] Discord: embed oferty, podsumowanie, obniżki cen, osobne webhooki dla wynajmu i sprzedaży.
  - [x] Alerty: blokada portalu 3 razy z rzędu, brak udanego przebiegu od 3 h (najwyżej raz na 6 h).
  - [ ] Alert: błędy AI w ponad połowie wywołań (po fazie 6).
  - [ ] Telegram.
- [ ] `notify:test`, `telegram:chat-id`.
  - [x] `notify:test discord`.

Gotowe gdy: wiadomość testowa dochodzi na oba kanały, nowa oferta z oceną powyżej progu przychodzi automatycznie, pierwszy przebieg daje jedno podsumowanie.

## Faza 8: utrwalenie

Status: do zrobienia

- Backup i `restore.sh` z testem przywracania.
- `deploy.sh`.
- Przegląd logów i błędów po 24 h działania, poprawki.
- README projektu (w katalogu głównym): jak dodać wyszukiwanie, jak sprawdzić status, jak zaktualizować.

Gotowe gdy: system działa 24 h bez ręcznej pomocy, backup jest i daje się przywrócić.

## Faza 9 (opcjonalnie)

- ✅ Kolejne portale (2026-11-18): Rzeszowiak, Morizon, Gratka, Nieruchomości-online, Domiporta, Adresowo. Szczegóły w SCRAPERS.md.
- Playwright jako osobna usługa, tylko jeśli któryś portal tego wymaga.
- ✅ Panel Filament (2026-11-18): wyszukiwania, oferty ze statusami i notatkami, przebiegi, ustawienia całej aplikacji, strona główna z liczbami. Domena `nieruchomosci.wilusz.tech` przez proxy Cloudflare, HTTPS (Let's Encrypt), dostęp tylko z adresów z `PANEL_ALLOWED_IPS`. 2FA do zrobienia.
- Oznaczanie ofert z Telegrama przyciskami („ciekawe”, „odrzuć”), żeby AI uczyło się preferencji z przykładów.

## Zrobione 2026-11-18 (pomysły z listy)

- ✅ Czas dojazdu autem i rowerem do miejsc właściciela (OSRM u nas, adresy z OpenStreetMap).
- ✅ Mapa ofert w panelu.
- ✅ Wykrywanie oszustw: zwroty, cena poniżej rynku, to samo zdjęcie w innej ofercie.

## Na później (pomysły zatwierdzone przez właściciela 2026-11-18)

- AI uczy się z decyzji: oferty ulubione i odrzucone jako przykłady w prompcie oceny.
- Wiadomość do ogłoszeniodawcy: przycisk, AI pisze pytania o brakujące informacje (kaucja, media, zwierzęta, termin, parking).
- Lista pytań na oglądanie dla statusu "Oglądanie" i termin w kalendarzu (iCal).
- Oferty, które zniknęły z portalu: status nieaktualnej, czas schodzenia ofert, dokładniejsze statystyki.
- Okazje: oferty wyraźnie tańsze niż mediana za m² w dzielnicy, obniżki cen w jednym miejscu.
- Asystent z działaniami: statusy, notatki, nowe wyszukiwania z czatu.
- Tygodniowy raport rynku na Discordzie i w panelu.
- Nadal otwarte z wcześniej: backup bazy (faza 8), 2FA w panelu, `deploy.sh`, Telegram.

