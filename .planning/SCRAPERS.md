# Scrapery

## Ogólne zasady (obowiązkowe)

- Tylko strony z listą wyników wyszukiwania. Nie otwieramy każdego ogłoszenia osobno, chyba że lista nie zawiera potrzebnych danych. Wtedy tylko dla nowych ogłoszeń.
- Maksymalnie 3 strony wyników na przebieg wyszukiwania, sortowanie od najnowszych. Nowe ogłoszenia są na początku, więc to wystarcza.
- Przerwa między zapytaniami do tego samego portalu: `SCRAPER_DELAY_MS` (domyślnie 4000 ms) plus losowe 0 do 2000 ms.
- Brak równoległych zapytań do jednego portalu (rate limiter w Redis na portal).
- Jeden stały, normalny nagłówek User-Agent przeglądarki z `.env`. Bez rotacji, bez proxy.
- Odpowiedź 403 lub 429: przerwij przebieg, zapisz `scrape_runs.status = blocked`, wstrzymaj portal na 2 godziny (klucz w Redis). Po 3 blokadach z rzędu wyślij alert na kanał powiadomień.
- Odpowiedź 5xx lub timeout: maksymalnie 2 ponowienia z przerwą 30 s.
- Brak CAPTCHA solverów, logowania, omijania zabezpieczeń.

## Sposób pobierania

Preferowana kolejność:

1. Dane JSON osadzone w HTML (np. tag `<script id="__NEXT_DATA__">` lub podobny stan aplikacji).
2. Publiczny endpoint JSON, z którego korzysta sama strona (sprawdzić w zakładce sieci przeglądarki).
3. Parsowanie HTML (Symfony DomCrawler).
4. Przeglądarka (Playwright) tylko w ostateczności, faza opcjonalna.

## Otodom

Sprawdzone 2026-11-17 (faza 3):

- URL: `https://www.otodom.pl/pl/wyniki/{wynajem|sprzedaz}/{mieszkanie|dom}/{lokalizacja}?priceMin=&priceMax=&areaMin=&areaMax=&roomsNumber=[TWO,THREE]&by=LATEST&direction=DESC&page=2`.
- Lokalizacja z mapy miast w `config/scrapers.php` (`otodom.locations`): Kraków, Warszawa, Wrocław, Gdańsk, Poznań, Łódź, Rzeszów. Nowe miasto trzeba dopisać.
- Filtr pokoi przyjmuje tylko ONE do FIVE. Dzielnice filtruje `ListingMatcher` po pobraniu.
- Dane: `__NEXT_DATA__` → `props.pageProps.data.searchAds.items`, 36 ofert na stronę. Na liście nie ma opisu oferty.
- Fixtures: `tests/Fixtures/otodom/search_rent.html`, `search_sale.html` (tylko `__NEXT_DATA__`).

## OLX

Sprawdzone 2026-11-17 (faza 4):

- Endpoint JSON strony: `https://www.olx.pl/api/v1/offers/?offset=&limit=40&category_id=&region_id=&city_id=&sort_by=created_at:desc&filter_float_price:from/to&filter_float_m:from/to&filter_enum_rooms[]=one..four`.
- Identyfikatory z `/api/v1/friendly-links/query-params/nieruchomosci,{mieszkania|domy},{wynajem|sprzedaz},{miasto}/`, zapamiętane na 30 dni. Nie trzeba mapy miast.
- Lista zawiera opis. Dzielnica tylko w dużych miastach (np. Kraków), w Rzeszowie brak.
- Oferty skopiowane z Otodom mają `partner.code = otodom_pl` i `external_url` z adresem oferty Otodom.
- OLX (CloudFront) odrzuca HTTP/1.1 z nagłówkiem przeglądarki. Zapytania idą przez HTTP/2, decyzja właściciela (STATUS.md).
- Fixtures: `tests/Fixtures/olx/*.json` bez danych ogłoszeniodawców.

## Mapowanie filtrów

Każdy scraper zamienia obiekt `Search` na URL portalu. Jeśli portal nie obsługuje jakiegoś filtra w URL (np. lista dzielnic), filtruj po pobraniu w `ListingMatcher`.

## Deduplikacja

1. W obrębie portalu: unique(portal, external_id). Przy ponownym znalezieniu aktualizuj `last_seen_at`, a przy zmianie ceny dodaj wpis do `listing_price_changes`.
2. Między portalami (ta sama oferta na Otodom i OLX): najpierw dokładnie po `external_url`, potem heurystyka: to samo miasto, cena różna o mniej niż 2%, metraż różny o mniej niż 1 m2, ten sam typ transakcji, widziane w ciągu 7 dni. Takie oferty dostają `duplicate_of_id` i nie wywołują drugiego powiadomienia. Usługa `DuplicateDetector`.

## Testy

- Każdy scraper ma test parsowania na fixture: liczba ogłoszeń, poprawne pola dla 2 lub 3 przykładowych ofert.
- Test budowania URL dla kilku kombinacji filtrów.
- Test reakcji na 403/429 (mock HTTP).
- Komenda `php artisan scraper:probe {portal} {search_id}` do ręcznego sprawdzenia na żywo (wypisuje znalezione oferty, nic nie zapisuje).
