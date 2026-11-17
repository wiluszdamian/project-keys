# API

Autoryzacja: Laravel Sanctum, token Bearer. Jeden użytkownik. Token tworzy komenda `php artisan api:token`.

Wszystkie odpowiedzi JSON. Prefiks `/api`.

| Metoda | Ścieżka | Opis |
|---|---|---|
| GET | /health | bez autoryzacji, status bazy, redis, kolejki, czas ostatniego przebiegu |
| GET | /searches | lista wyszukiwań |
| POST | /searches | utworzenie (walidacja wszystkich pól z DATA_MODEL.md) |
| GET | /searches/{id} | szczegóły + statystyki (liczba ofert, ostatni przebieg) |
| PATCH | /searches/{id} | zmiana |
| DELETE | /searches/{id} | usunięcie |
| POST | /searches/{id}/run | uruchom przebieg teraz |
| POST | /searches/{id}/pause, /resume | wstrzymaj, wznów |
| GET | /searches/{id}/listings | dopasowane oferty z oceną, sortowanie: najnowsze lub najlepsza ocena, paginacja, filtr `min_score` |
| GET | /listings/{id} | szczegóły oferty, historia cen, oceny |
| GET | /scrape-runs | ostatnie przebiegi, filtr po portalu i statusie |

Limity: 60 zapytań na minutę na token.

Komendy artisan jako alternatywa dla API (wygodne przez SSH):

- `search:create` (interaktywnie pyta o pola)
- `search:list`
- `search:run {id}`
- `search:pause {id}`, `search:resume {id}`
