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

Limity: 60 zapytań na minutę na token.

Dostęp: API słucha tylko na `127.0.0.1:8080` na serwerze, z zewnątrz przez tunel SSH. Wszystko poza `/health` wymaga tokenu.

Wszystkie endpointy działają (fazy 2 i 5).

Komendy artisan jako alternatywa dla API (wygodne przez SSH):

- `search:create` (interaktywnie pyta o pola)
- `search:list`
- `search:run {id}`
- `search:pause {id}`, `search:resume {id}`
- `api:token {nazwa}`, `scraper:probe {portal} {id}`, `notify:test`, `ai:usage`, `alerts:check`, `search:dispatch-due` (uruchamia scheduler)
