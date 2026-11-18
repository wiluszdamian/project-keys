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

Powiadomienia w wyszukiwaniu: `notify_enabled` (bool), `notify_mode` (`instant`, `every_hours`, `daily`), `notify_every_hours` (1 do 72, wymagane przy `every_hours`), `notify_daily_at` (`HH:MM`, wymagane przy `daily`), `min_score_to_notify`.

Zbiorcze powiadomienia kanałów (wynajem i sprzedaż osobno):

- `GET /notification-settings`: `{"data": {"rent": {"schedule", "every_hours", "daily_at", "last_sent_at"}, "sale": {...}}}`.
- `PUT /notification-settings`: np. `{"rent": {"schedule": "daily", "daily_at": "20:00"}, "sale": {"schedule": "every_hours", "every_hours": 4}}`. `schedule`: `per_search` (decydują wyszukiwania), `every_hours`, `daily`.

Limity: 60 zapytań na minutę na token.

Dostęp: `http://155.133.197.67` tylko z adresów z `PANEL_ALLOWED_IPS`, albo tunel SSH. Wszystko poza `/health` wymaga tokenu. Ten sam adres obsługuje panel `/admin`.

Wszystkie endpointy działają (fazy 2 i 5).

Komendy artisan jako alternatywa dla API (wygodne przez SSH):

- `search:create` (interaktywnie pyta o pola)
- `search:list`
- `search:run {id}`
- `search:pause {id}`, `search:resume {id}`
- `api:token {nazwa}`, `scraper:probe {portal} {id}`, `notify:test`, `ai:usage`, `alerts:check`, `search:dispatch-due` (uruchamia scheduler)
