# Model danych (PostgreSQL)

## searches

| Kolumna | Typ | Uwagi |
|---|---|---|
| id | bigint pk | |
| name | string | np. „Kraków wynajem 2 pokoje” |
| deal_type | enum: rent, sale | |
| property_type | enum: flat, house | na start tylko flat |
| city | string | |
| districts | jsonb nullable | lista dzielnic, null = całe miasto |
| price_min, price_max | integer nullable | PLN |
| area_min, area_max | integer nullable | m2 |
| rooms_min, rooms_max | smallint nullable | |
| radius_km | smallint nullable | promień wokół miasta w km, null = tylko to miasto |
| market | string nullable | `primary` albo `secondary`, null = oba rynki |
| move_in_ready | boolean default false | odrzuca oferty, w których nie da się od razu zamieszkać: stan deweloperski, do wykończenia, do remontu (z danych portalu albo opisu). Meble nie są wymagane |
| exclude_visualizations | boolean default false | odrzuca oferty z wizualizacjami w tytule lub opisie |
| portals | jsonb | np. ["otodom","olx"] |
| ai_preferences | text nullable | wolny tekst dla AI |
| notify_channels | jsonb | np. ["telegram"], ["discord"], oba |
| min_score_to_notify | smallint default 6 | |
| is_active | boolean default true | |
| last_run_at | timestamp nullable | |
| first_run_completed | boolean default false | do trybu podsumowania przy pierwszym przebiegu |
| timestamps | | |

## listings

Okazje: `deal_reasons` (jsonb, lista `{type, text}`), `deal_at` (od kiedy jest okazją), `deal_notified_at` (kiedy poszło powiadomienie).

| Kolumna | Typ | Uwagi |
|---|---|---|
| id | bigint pk | |
| portal | string | otodom, olx |
| external_id | string | ID z portalu |
| url | text | |
| title | string | |
| deal_type | enum | |
| price | integer nullable | PLN |
| rent_extra | integer nullable | czynsz administracyjny, jeśli podany |
| area | decimal(7,2) nullable | |
| price_per_m2 | integer nullable | liczone przy zapisie |
| rooms | smallint nullable | |
| floor | string nullable | |
| city, district, street | string nullable | |
| is_private_owner | boolean nullable | prywatne czy agencja, jeśli portal podaje |
| description | text nullable | |
| image_url | text nullable | tylko link do pierwszego zdjęcia |
| raw | jsonb | surowe dane z portalu, do debugowania |
| content_hash | string | hash tytułu, ceny, metrażu i opisu |
| duplicate_of_id | fk listings nullable | ta sama oferta z innego portalu, zapisana wcześniej (faza 4) |
| first_seen_at, last_seen_at | timestamp | |
| inactive_at | timestamp null | ogłoszenie zdjęte z portalu (404/410, komunikat „archiwalne”, przekierowanie); znów w wynikach = null |
| inactive_reason | string null | skąd wiemy, że zdjęte |
| availability_checked_at | timestamp null | ostatnie sprawdzenie strony ogłoszenia (`listings:check-active`) |
| published_at | timestamp null | data dodania ogłoszenia według portalu (Otodom, OLX, Morizon, Domiporta, Rzeszowiak); najwcześniejsza widziana |
| viewing_at | timestamp null | umówione oglądanie |
| viewing_questions | jsonb null | pytania od AI na oglądanie: lista `{question, why}` |
| viewing_questions_at | timestamp null | kiedy AI przygotowało pytania |
| viewing_reminded_at | timestamp null | wysłane przypomnienie o oglądaniu (zerowane przy zmianie terminu) |
| timestamps | | |

Indeksy: unique(portal, external_id), index(city, district), index(last_seen_at).

## search_listing

| Kolumna | Typ |
|---|---|
| id | bigint pk (dla modelu pivot) |
| search_id, listing_id | fk, unique razem |
| matched_at | timestamp |
| notified_at | timestamp nullable |
| notify_skipped_reason | string nullable: below_threshold, digest, duplicate, no_channel |

## listing_analyses

| Kolumna | Typ | Uwagi |
|---|---|---|
| listing_id | fk | |
| search_id | fk | ocena zależy od preferencji wyszukiwania |
| model | string | ID modelu |
| score | smallint | 1 do 10 |
| monthly_cost | integer nullable | pełny koszt miesięczny wg AI (najem + czynsz + opłaty z opisu) |
| cost_breakdown | jsonb nullable | rent, admin_fee, other_fees, note |
| sub_scores | jsonb nullable | cost, location, standard, fit (1 do 10) |
| summary | text | |
| pros | jsonb | |
| red_flags | jsonb | |
| input_tokens, output_tokens | integer nullable | |
| listing_content_hash | string nullable | content_hash oferty w chwili oceny |
| timestamps | | |

Unique(listing_id, search_id): każdą parę oceniamy raz. Ponowna ocena tylko gdy zmieni się `content_hash` ogłoszenia.

## listing_price_changes

listing_id, old_price, new_price, changed_at.

## scrape_runs

search_id, portal, started_at, finished_at, status (running, ok, blocked, error), pages_fetched, found_count, new_count, error_message.

Służy do diagnozy i do wykrywania blokad (kilka `blocked` z rzędu = wstrzymaj portal i wyślij alert).

## products (monitor cen, od 2026-11-24)

Produkt, który osoba chce kupić. Oferty w sklepach w `product_offers`.

| Pole | Typ | Uwagi |
|---|---|---|
| name, image_url, brand | | ze strony głównego sklepu, nazwę można zmienić |
| gtin, mpn | string nullable | EAN i kod producenta ze strony, pomagają znaleźć produkt w innych sklepach |
| search_query | string nullable | czego szukać w innych sklepach; puste = AI albo początek nazwy |
| target_price | decimal nullable | cena docelowa: powiadomienie, gdy najlepsza cena do niej spadnie |
| notify_drops, min_drop_percent, notify_rises, notify_stock | | co wysyłać (obniżki od X%, podwyżki, dostępność) |
| check_every_hours | smallint nullable | null = `PRODUCTS_CHECK_EVERY_HOURS` (3) |
| is_active | bool | czy sprawdzać |
| best_price, best_offer_id | | najtańsza dostępna oferta w PLN |
| initial_price, lowest_price (+ lowest_price_at), highest_price | decimal | historia najlepszej ceny |
| last_checked_at, offers_searched_at | timestamp | ostatnie sprawdzenie i szukanie w innych sklepach |
| ai_advice, ai_advice_at | jsonb | ocena AI: `verdict` (buy, good, wait), `score` 1-10, `summary`, `reasons`, `tips`, `price` |

## product_offers

product_id, url (bez parametrów śledzących), url_key (sha1, unique z product_id), shop (domena), title, price, regular_price (przed promocją), lowest_30d_price (Omnibus), currency, available (null = nie wiadomo), source (`manual` albo `search`), is_primary, is_active, extracted_by (`json-ld`, `meta`, `microdata`, `shop`, `text`, `ai`), match_note (dlaczego AI uznało ofertę za ten sam produkt), last_checked_at, last_error, failures.

## product_prices

product_offer_id, price, available, checked_at. Tylko pierwszy odczyt i zmiany ceny albo dostępności.

## product_shops

Sklepy do darmowego szukania produktów: domain (unique, bez www), name, sitemap_url (puste = z robots.txt albo /sitemap.xml), is_active, source (`manual`, `ai`, `default`), status (`ok`, `empty`, `blocked`, `error`), last_error, urls_count, synced_at.

## product_shop_urls

product_shop_id, url, slug (słowa z adresu małymi literami bez polskich znaków). Adresy z mapy strony sklepu, bez zakazanych w robots.txt, najwyżej 200 000 na sklep.
