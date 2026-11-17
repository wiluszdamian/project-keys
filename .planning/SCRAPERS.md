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

- **SPRAWDŹ** aktualny format URL wyszukiwania (transakcja, typ, lokalizacja, filtry ceny, metrażu, pokoi, sortowanie od najnowszych).
- **SPRAWDŹ**, czy strona wyników zawiera JSON w `__NEXT_DATA__` i gdzie w nim są ogłoszenia, cena, metraż, pokoje, lokalizacja, typ ogłoszeniodawcy.
- Zapisz przykładową stronę jako fixture do testów (`tests/Fixtures/otodom/search_rent.html`).

## OLX

- **SPRAWDŹ** format URL kategorii mieszkań (wynajem, sprzedaż) i parametrów filtrów.
- **SPRAWDŹ**, czy strona używa wewnętrznego endpointu JSON z ofertami. Jeśli tak, użyj go z tymi samymi parametrami co strona.
- OLX pokazuje też ogłoszenia przekierowujące na Otodom. Takie oferty oznacz i deduplikuj (patrz niżej).
- Fixture do testów jak przy Otodom.

## Mapowanie filtrów

Każdy scraper zamienia obiekt `Search` na URL portalu. Jeśli portal nie obsługuje jakiegoś filtra w URL (np. lista dzielnic), filtruj po pobraniu w `ListingMatcher`.

## Deduplikacja

1. W obrębie portalu: unique(portal, external_id). Przy ponownym znalezieniu aktualizuj `last_seen_at`, a przy zmianie ceny dodaj wpis do `listing_price_changes`.
2. Między portalami (ta sama oferta na Otodom i OLX): heurystyka na start: to samo miasto, cena różna o mniej niż 2%, metraż różny o mniej niż 1 m2, ten sam typ transakcji, widziane w ciągu 7 dni. Takie oferty nie wywołują drugiego powiadomienia. Zapisz to jako osobną usługę, łatwą do zmiany.

## Testy

- Każdy scraper ma test parsowania na fixture: liczba ogłoszeń, poprawne pola dla 2 lub 3 przykładowych ofert.
- Test budowania URL dla kilku kombinacji filtrów.
- Test reakcji na 403/429 (mock HTTP).
- Komenda `php artisan scraper:probe {portal} {search_id}` do ręcznego sprawdzenia na żywo (wypisuje znalezione oferty, nic nie zapisuje).
