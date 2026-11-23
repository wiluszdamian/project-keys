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
- Lokalizacja z mapy miast w `config/scrapers.php` (`otodom.locations`): Kraków, Warszawa, Wrocław, Gdańsk, Poznań, Łódź, Rzeszów, Boguchwała, Tajęcina, Rudna Mała. Nowe miasto trzeba dopisać.
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

## Rzeszowiak

Sprawdzone 2026-11-18. Lokalny serwis ogłoszeń, HTML w ISO-8859-2, bez JSON.

- Osobne kategorie dla osób prywatnych i agencji (wynajem 210 i 260, sprzedaż 307 i 258). Przebieg pobiera obie.
- URL: `/Nieruchomosci-Mam-do-wynajecia-{kod}?min=&max=&r=mieszkanie`. Kod: kategoria (3 cyfry), strona (3), sortowanie 1 = najnowsze, 3 = 35 na stronę, 5 = ostatnie 30 dni, na końcu 5.
- Filtr tylko po cenie. Portal nie filtruje miejscowości, metrażu ani pokoi, a lista nie ma tych pól. Miejscowość sprawdzamy w tytule i opisie (dla Rzeszowa także ogłoszenia bez nazwy miejscowości, chyba że wymieniają inną z listy `rzeszowiak.other_places`). Metraż przy sprzedaży z ceny za m², przy wynajmie z tekstu, pokoje z tekstu.
- Strona ogłoszenia (przed oceną AI): metraż, pokoje, piętro, położenie (miasto i ulica), pełny opis. Pole "czynsz" przy wynajmie to zwykle sam najem.
- robots.txt nie ma aktywnych reguł.

## Morizon i Gratka

Sprawdzone 2026-11-18. Ta sama firma: te same oferty z tym samym `id` (`raw.network_id`), duplikaty łączymy dokładnie po tym id. Gratka nic nie dodaje ponad Morizon, dlatego nie jest włączona w wyszukiwaniach.

- Dane w `__NUXT_DATA__` (Nuxt 3, format devalue, dekoder `NuxtPayload`). Lista: `property-listing-data-…` → `searchResult.propertyGroups|properties.nodes` (oferta w `primary` przy grupach), 35 na stronę.
- robots.txt zabrania `sort=`, `ps[…]`, `rooms_min` itd., więc bez filtrów w adresie, filtruje `ListingMatcher`.
- Morizon: `/do-wynajecia/mieszkania/najnowsze/{lokalizacja}/?page=N` (od najnowszych przez ścieżkę, dozwolone). Gratka: `/nieruchomosci/mieszkania/{lokalizacja}[/wynajem]?page=N`, kolejność domyślna (ostatnio odświeżone).
- Mapy lokalizacji w config (`morizon.locations`, `gratka.locations`), edytowalne w panelu.
- Czynszu brak w danych, tylko w opisie (czyta AI). Strona oferty: pełny opis, dokładny metraż, piętro, rok budowy.
- Cloudflare przed oboma, raz zdarzył się błąd 520.

## Nieruchomości-online

Sprawdzone 2026-11-18.

- URL: `/szukaj.html?3,mieszkanie,{wynajem|sprzedaz},,{miasto},,,,{cena od-do},{metraż od-do},,,,,,{pokoje od-do}&p=N`. Domyślnie od najnowszych. Miejscowość po nazwie, bez mapy.
- Liczby z `buildCachedProps({…}).record_props`, tytuł, zdjęcie, piętro i opis z kafelków. Kafelki `data-pie="searchSupplement"` to oferty z innych miejsc spoza filtrów: pomijamy.
- Strona oferty: "Opłaty uzupełniające" (czynsz), pełny opis, piętro.
- robots.txt ma reguły tylko dla Googlebota i podobnych.

## Domiporta

Sprawdzone 2026-11-18.

- URL: `/mieszkanie/{wynajme|sprzedam}/{lokalizacja}?Price.From=&Price.To=&Surface.From=&Rooms.From=&SortingOrderDirection=SortDateDescending&PageNumber=N`, 36 na stronę. Lokalizacja z mapy (`podkarpackie/rzeszow`).
- Wyniki z ld+json `ItemList` (bez ofert "w promieniu 15 km"), czynsz i dzielnica z kafelków. Najem na doby pomijamy. Lokale deweloperów (`/nowe/`) mają id z przedrostkiem `nowe-`.
- Strona oferty: "Czynsz administracyjny", piętro, pełny opis, typ ogłoszeniodawcy.
- Homfi to ogłoszeniodawca na Domiporcie, nie osobny portal.

## Adresowo

Sprawdzone 2026-11-18. Głównie oferty bez pośredników.

- URL: `/mieszkania/{lokalizacja}/` i `/mieszkania-wynajem/{lokalizacja}/`, z pokojami `/f/mieszkania/{lokalizacja}/fp3p4p5p6`, strony `_l2`. Mapa lokalizacji (np. `boguchwala-o`).
- Brak sortowania po dacie i filtrów ceny i metrażu w adresie (tylko przez zablokowane w robots.txt `/offer-list/ajax/`). Czytamy do `max_pages` stron (40 ofert, potem po 120), resztę filtruje `ListingMatcher`.
- Tylko wyniki z `#offer-list-results` do pierwszego `</section>`, dalej są oferty z okolicy.
- Strona oferty: pełny opis i piętro z ld+json `Place`, typ ogłoszeniodawcy. Czynsz tylko w opisie.

## Stoprocent Nieruchomości

- Biuro z Rzeszowa, WordPress (motyw Bricks) z ofertami z Asari. robots.txt blokuje tylko `/wp-admin/`.
- Lista: publiczne API WordPressa `/wp-json/wp/v2/nieruchomosci?per_page=100&orderby=date&order=desc&_fields=id,date,modified,link,title`, wszystkie strony (ok. 600 ofert, biuro dodaje kilkanaście dziennie wszystkich rodzajów, a oferta najmu wisi tygodniami). Podaje tylko tytuł i adres, wszystkie rodzaje ofert razem. Działki, lokale i biura pomijamy po tytule, bez pobierania strony.
- Typ, transakcja, rynek, cena, metraż, pokoje i piętro są w tabeli „Przegląd” na stronie ogłoszenia (`.property_info_element`), miejscowość i pinezka w `data-map-markers` mapy (np. `/Podkarpackie/Rzeszowski/Krasne`), opis po nagłówku „OPIS”.
- Stronę ogłoszenia pobieramy raz na ofertę (klucz cache z `id` i `modified`, 30 dni), najwyżej `scrapers.stoprocent.max_new_offers` (40) nowych stron na przebieg.

## Rzeszowskie Nieruchomości

- Biuro z Rzeszowa, system Esti, zwykły HTML. robots.txt pozwala na wszystko.
- Kategorie: `/mieszkania/rynek-wtorny/`, `/mieszkania/nowe-rynek-pierwotny/`, `/mieszkania/wynajem/` (domy: `/domy/...`), strony `strona,N/`, 12 ofert na stronę, od najnowszych.
- Lista: miejscowość, ulica, cena, metraż, pokoje. Tytuł z adresu oferty. Rynek z kategorii.
- Strona ogłoszenia: opis (`section.text .text-box p`), „Szczegóły” i „Pomieszczenia” (`ul.text__ul`), pinezka z `L.marker([lat, lng])`.

## Pierwsze Piętro (1pietro.net)

- Biuro z Rzeszowa, system Esti jak Rzeszowskie Nieruchomości, inny szablon. robots.txt nie ma (adres przekierowuje na stronę główną).
- Kategorie: `/mieszkania/sprzedaz/`, `/mieszkania/wynajem/` (domy: `/domy/...`), strony `strona,N/`, 18 ofert na stronę, od najnowszych. Rynek tylko w formularzu (POST), więc czytamy go z opisu („rynek wtórny”).
- Lista (`article.single-offer`): tytuł w `alt` zdjęcia, miejscowość, ulica, cena, metraż, pokoje.
- Strona ogłoszenia: opis (`.lang-description .lang-text`), numer oferty, pinezka z `L.marker([lat, lng])`, piętro z opisu.

## Promień wokół miasta

- Wyszukiwanie może mieć `radius_km`. Otodom (`distanceRadius`), OLX (`distance`), Domiporta (`Distance`) i Nieruchomości-online (pole odległości) dostają promień w adresie, zaokrąglony w górę do wartości portalu.
- Rzeszowiak, Stoprocent i Rzeszowskie Nieruchomości nie filtrują miejsca, więc sprawdzamy odległość miejscowości oferty od środka miasta (Nominatim, cache).
- Morizon i Adresowo nie mają promienia w adresie (Morizon: filtry zabronione w robots.txt), szukają tylko w mieście.
- Po geokodowaniu oferty (przed oceną AI) oferta dalej niż promień (z zapasem 0,5 km na pinezkę i ulicę, 1,5 km na dzielnicę i miasto) jest odłączana.

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
