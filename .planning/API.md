# API

Autoryzacja: Laravel Sanctum, token Bearer. Jeden użytkownik. Token tworzy komenda `php artisan api:token`.

Wszystkie odpowiedzi JSON. Prefiks `/api`.

| Metoda | Ścieżka | Opis |
|---|---|---|
| GET | /health | bez autoryzacji: `status`, `checks.database`, `checks.redis`, `queue_size`, `last_scrape_at` |
| GET | /searches | lista wyszukiwań |
| POST | /searches | utworzenie (walidacja wszystkich pól z DATA_MODEL.md) |
| GET | /searches/{id} | szczegóły + statystyki (liczba ofert, ostatni przebieg) |
| PATCH | /searches/{id} | zmiana |
| DELETE | /searches/{id} | usunięcie |
| POST | /searches/{id}/run | uruchom przebieg teraz (202, przebieg w kolejce) |
| POST | /searches/{id}/pause, /resume | wstrzymaj, wznów |
| GET | /searches/{id}/listings | dopasowane oferty z oceną, sortowanie: najnowsze lub najlepsza ocena, paginacja, filtr `min_score` |
| GET | /listings/{id} | szczegóły oferty, historia cen, oceny |
| GET | /scrape-runs | ostatnie przebiegi, filtry `portal`, `status`, `search_id` |

Promień wokół miasta: `radius_km` (1 do 50, null = tylko miasto). Filtry rynku w wyszukiwaniu (od 2026-11-23): `market` (`primary`, `secondary` albo `null` = oba), `move_in_ready` (bool, tylko gotowe do zamieszkania), `exclude_visualizations` (bool). Odrzucają tylko oferty, o których wiadomo, że nie pasują. Oferty już przypisane sprawdza od nowa `php artisan search:refilter {id}` (`--dry-run` tylko liczy).

Powiadomienia w wyszukiwaniu: `notify_enabled` (bool), `notify_mode` (`instant`, `every_hours`, `daily`), `notify_every_hours` (1 do 72, wymagane przy `every_hours`), `notify_daily_at` (`HH:MM`, wymagane przy `daily`), `min_score_to_notify`.

Miejsca dojazdu (np. praca): `GET/POST /commute-places`, `GET/PATCH/DELETE /commute-places/{id}`. Pola: `name`, `address`, opcjonalnie `lat`, `lng` (bez nich szukamy adresu w OpenStreetMap), `max_minutes`, `is_active`. Oferta w `GET /listings/{id}` ma `commutes` (miejsce, `mode` car albo bike, `minutes`, `km`), `lat`, `lng`, `geo_precision` i `risk_flags`.

Zbiorcze powiadomienia kanałów (wynajem i sprzedaż osobno):

- `GET /notification-settings`: `{"data": {"rent": {"schedule", "every_hours", "daily_at", "last_sent_at"}, "sale": {...}, "weekly_report": true, "alerts_discord": false}}`.
- `PUT /notification-settings`: np. `{"rent": {"schedule": "daily", "daily_at": "20:00"}, "sale": {"schedule": "every_hours", "every_hours": 4}}`. `schedule`: `per_search` (decydują wyszukiwania), `every_hours`, `daily`. Dodatkowo `weekly_report` (raport rynku w soboty o 20:00) i `alerts_discord` (alerty także na Discorda), oba bool.
- `GET /market-reports?deal_type=rent&limit=4`: tygodniowe raporty rynku od najnowszego: `id`, `deal_type`, `period_start`, `period_end`, `sent_at`, `data` (`totals` z `median_total` i `total_known` dla najmu z czynszem, `searches`, `districts`, `rooms`, `price_drops`, `best_new`, `has_previous`, `time_on_market`, od 2026-11-25 także `ai_summary` (`text`, `points`), `supply` (`new`, `gone`, `net`, `active_change`), `trend` (8 tygodni: `label`, `active`, `new`, `median_price`, `median_price_per_m2`, `median_total`), `your_list`, `deals` (`count`, `top`), `products`).

Limity: 60 zapytań na minutę na token.

Dostęp: `https://nieruchomosci.wilusz.tech/api` z każdego adresu (API nie ma hasła przeglądarki, tylko token). Zapasowo `http://155.133.197.67` tylko z adresów z `PANEL_ALLOWED_IPS`, albo tunel SSH. Wszystko poza `/health` wymaga tokenu. Ten sam adres obsługuje panel `/admin`.

Wszystkie endpointy działają (fazy 2 i 5).

Komendy artisan jako alternatywa dla API (wygodne przez SSH):

- `user:create {login} --admin`, `user:reset-passkeys {login}`, `user:reset-2fa {login}`
- `search:create` (interaktywnie pyta o pola)
- `search:list`
- `search:run {id}`
- `search:pause {id}`, `search:resume {id}`
- `listings:score-missing` (ocena AI dla aktywnych ofert bez oceny, `--days=3`, `--dry-run`)
- `pois:import` (sklepy, żłobki, przedszkola, szkoły i przychodnie z OpenStreetMap przez Overpass, potem infrastruktura ofert; co miesiąc). Gdy Overpass nie działa: `scripts/pois-extract.sh` na serwerze (lokalna mapa)
- `listings:check-active` (czy ogłoszenia niewidziane od 6 h są jeszcze na portalu; zdjęte dostają `inactive_at`; co 30 minut, `--limit=`, `--id=`)
- `viewings:remind` (przypomnienie w panelu o oglądaniu na 2 h przed terminem, co 5 minut)
- `deals:refresh` (przelicza okazje i wysyła powiadomienia o nowych, `--silent` bez wysyłki)
- `search:refilter {id...}` (odłącza przypisane oferty, które nie pasują do obecnych filtrów)
- `api:token {nazwa}`, `scraper:probe {portal} {id}`, `notify:test`, `ai:usage`, `alerts:check`, `search:dispatch-due` (uruchamia scheduler)

Asystent AI (narzędzie `get_listing`, od 2026-11-24) dostaje też `lease_terms` (warunki najmu: rodzaj umowy, okres, kaucja, dostępne od, zwierzęta, ogłoszeniodawca, prowizja) i `time_on_market` (jak długo oferta wisi i po ilu dniach schodzą podobne).

Zdjęte oferty (od 2026-11-24): `inactive_at` (od kiedy nie ma ogłoszenia na portalu) i `inactive_reason`, `null` dla aktywnej.

Okazje (od 2026-11-23): oferta w `GET /listings/{id}` i na listach ma `deal_reasons` (lista `{type, text}`, typy `below_transactions`, `price_drop`, `below_market`) i `deal_at`, gdy jest okazją.

## Monitor cen produktów (od 2026-11-24)

| Metoda | Ścieżka | Opis |
|---|---|---|
| GET | /products | produkty ze sklepami (`?active=1` tylko śledzone) |
| POST | /products | `url` (wymagany) i opcjonalnie `target_price`, `notify_drops`, `min_drop_percent`, `notify_rises`, `notify_stock`, `check_every_hours` (1 do 168), `is_active`, `note`. Od razu sprawdza cenę i nazwę (201). Ten sam link drugi raz: 422 |
| GET | /products/{id} | produkt: `best_price`, `best_offer_id`, `initial_price`, `lowest_price`, `highest_price`, `change_percent`, `target_reached`, `ai_advice`, `offers` |
| PATCH | /products/{id} | zmiana ustawień (te same pola co przy dodaniu i `name`) |
| DELETE | /products/{id} | usunięcie z historią |
| POST | /products/{id}/check | sprawdza ceny od razu, zwraca produkt i `events` (`drop`, `rise`, `target`, `back_in_stock`, `out_of_stock`) |
| GET | /products/{id}/prices | historia: `offer_id`, `shop`, `price`, `available`, `checked_at` (tylko zmiany) |
| POST | /products/{id}/offers | `url`: ten sam produkt w innym sklepie (201) |
| DELETE | /products/{id}/offers/{offer} | usuwa sklep |
| POST | /products/{id}/find-offers | szukanie w innych sklepach w tle (202), wynik w panelu. 422, gdy nie ma sklepów, AI ani klucza Brave |
| POST | /products/{id}/advice | ocena ceny przez AI w tle (202) |

Oferta (`offers`): `id`, `url`, `shop`, `title`, `price`, `regular_price`, `lowest_30d_price`, `currency`, `available`, `source` (`manual`, `search`), `matched` (true ten sam produkt, false inny wariant według AI, nie śledzona; null ręczny link), `is_primary`, `is_active`, `extracted_by`, `match_note`, `last_error`, `failures`, `last_checked_at`.

Produkt ma też `search_query` (czego szukać w innych sklepach, np. "Cybex Balios S Lux"; puste = ustali AI albo początek nazwy).

Sklepy do darmowego szukania (mapy stron): `GET /product-shops`, `POST /product-shops` (`domain`, opcjonalnie `name`, `sitemap_url`, `is_active`; od razu pobiera mapę w tle), `PATCH/DELETE /product-shops/{id}`, `POST /product-shops/{id}/sync`. Pola: `status` (`ok`, `empty`, `blocked`, `error`), `last_error`, `urls_count`, `synced_at`, `source` (`manual`, `ai`, `default`).

Komendy: `products:sync-shops` (mapy stron starsze niż tydzień, w niedziele o 5:00; `{domena}` od razu z wynikiem, `--all` wszystkie), `products:check` (co 10 minut produkty, które czekają; `--id=` wybrane, `--sync` od razu z wynikiem), `products:find-offers {id}` (od razu) albo `--due` (codziennie o 11:00, co `PRODUCTS_SEARCH_EVERY_DAYS` dni).

## Ceny transakcyjne (od 2026-11-20)

Oferta (`GET /listings/{id}` i listy ofert) ma pole `transaction_comparison` (tylko sprzedaż, inaczej `null`):
`status` (`below`, `market`, `above`, `too_few`), `median_price_per_m2`, `p25_price_per_m2`, `p75_price_per_m2`,
`offer_price_per_m2`, `diff_percent`, `count`, `radius_m`, `months`, `market` (`primary`, `secondary` albo `null` = oba rynki),
`listing_market`, `latest_date`, `nearest` (5 najbliższych transakcji: `date`, `area`, `rooms`, `floor`, `price`, `price_per_m2`, `market`, `distance_m`).
Raport rynku sprzedaży ma w `data.transactions`: `compared`, `above`, `market`, `below`, `median_diff_percent`, `last_3_months.{primary,secondary}`.
