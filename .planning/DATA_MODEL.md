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
