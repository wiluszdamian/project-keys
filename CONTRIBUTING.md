# Praca nad kodem

Ten plik jest dla każdego, kto zmienia kod project-keys. Czym jest projekt i jak go uruchomić:
[README](README.md).

## Wprowadzanie zmiany

1. Utwórz branch od `develop`.
2. Wprowadź zmianę razem z testami. Uruchom testy i Pint: [Testy](#testy).
3. Otwórz pull request do `develop`. GitHub Actions uruchomi testy i Pint.

## Commity

- Po angielsku, jedno krótkie zdanie z prefiksem: `feat:`, `fix:`, `chore:`, `docs:`, `test:`,
  `refactor:`, `perf:`.
- Przykłady: `feat: digest lists only offers above score threshold`,
  `fix: key Otodom offers by URL code`.

## Zasady w kodzie

- **Sekrety tylko w `.env`.** Nigdy w repozytorium, logach, testach ani komunikatach błędów.
  Adres webhooka Discorda też jest sekretem.
- **Bez prawdziwych zapytań w testach.** `TestCase` włącza `Http::preventStrayRequests()`.
  Odpowiedzi portali są w `tests/Fixtures` (bez danych kontaktowych ogłoszeniodawców), AI
  podmieniasz przez `Agent::fake()`.
- **Grzeczne scrapowanie.** Każde zapytanie do portalu idzie przez `HttpFetcher`: przerwy,
  ponowienia, pauza po 403 i 429. Nie omijaj go i nie skracaj przerw.
- **Komentarze i teksty dla użytkownika po polsku**, nazwy w kodzie po angielsku.
- **Decyzje zapisuj w `.planning/STATUS.md`** w sekcji Decyzje, z datą i powodem. Ukończone
  zadania odhaczaj w `.planning/ROADMAP.md`.

## Testy

Testy działają na PostgreSQL, w osobnej bazie `mieszkania_test`. `TestCase` sprawdza jej nazwę
i przerywa testy przy każdej innej bazie, żeby nie skasować prawdziwych danych.

Na serwerze z działającym Compose:

```bash
scripts/test.sh                  # wszystkie testy
scripts/test.sh --filter=Otodom  # wybrane testy
docker run --rm -v "$PWD":/var/www/html -u "$(id -u):$(id -g)" project-keys-app vendor/bin/pint
```

`scripts/test.sh` uruchamia testy w obrazie aplikacji z aktualnym kodem podpiętym z dysku, więc
nie trzeba przebudowywać obrazu. Obraz musi mieć narzędzia deweloperskie (`INSTALL_DEV=1` w
`.env`).

Baza testowa tworzy się raz:

```bash
docker compose exec postgres psql -U mieszkania -d mieszkania \
  -c "CREATE ROLE test LOGIN PASSWORD 'test'" \
  -c "CREATE DATABASE mieszkania_test OWNER test"
```

## Wdrożenie

Na serwerze, w katalogu projektu:

```bash
git pull
docker compose build app
docker compose up -d
docker compose exec app php artisan migrate --force
```

`up -d` restartuje też workery, więc biorą nowy kod. Po zmianie `.env` wystarczy
`docker compose up -d`.

## Gdzie co jest

| Katalog | Zawartość |
|---|---|
| `app/Scrapers` | Otodom i OLX, wspólny `HttpFetcher`, rejestr portali |
| `app/Services` | zapis ofert, duplikaty, dopasowanie do filtrów, ocena AI, statystyki rynku |
| `app/Ai/Agents` | prompt i schemat odpowiedzi AI |
| `app/Jobs` | przebieg wyszukiwania, pobieranie portalu, ocena, wysyłka |
| `app/Notifications` | `Notifier` i wiadomości Discorda |
| `app/Console/Commands` | polecenia `artisan` |
| `config/scrapers.php`, `notify.php`, `scoring.php` | ustawienia portali, powiadomień i AI |
| `docker/`, `docker-compose.yml`, `Dockerfile` | kontenery |
| `.planning/` | plan, architektura, status i decyzje |

## Nowy portal

1. Klasa w `app/Scrapers` z interfejsem `PortalScraper`, wpis w `config/scrapers.php`.
2. Gdy lista wyników nie ma opisu, dodaj `EnrichesListings` (pobranie strony ogłoszenia przed
   oceną AI).
3. Prawdziwa odpowiedź portalu w `tests/Fixtures/<portal>/`, bez danych osobowych, i testy
   parsera.
4. Sprawdź na żywo przez `scraper:probe <portal> <id>`, zanim włączysz portal w wyszukiwaniach.
