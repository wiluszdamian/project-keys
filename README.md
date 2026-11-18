<div align="center">

<p align="center"><picture><source media="(prefers-color-scheme: dark)" srcset=".github/assets/project-keys-dark.svg"><img src=".github/assets/project-keys-light.svg" alt="project-keys" width="380"></picture></p>

<h3>
Prywatny serwis, który szuka mieszkań za ciebie.
</h3>

<p>
<a href="#jak-to-działa"><b>Jak to działa</b></a> ·
<a href="#uruchomienie"><b>Uruchomienie</b></a> ·
<a href="#panel"><b>Panel</b></a> ·
<a href="#codzienna-obsługa"><b>Codzienna obsługa</b></a> ·
<a href="CONTRIBUTING.md"><b>Praca nad kodem</b></a>
</p>

</div>

project-keys co 20 minut przegląda portale z ogłoszeniami (Otodom, OLX, Rzeszowiak, Morizon, Gratka,
Nieruchomości-online, Domiporta, Adresowo) według zapisanych wyszukiwań. Nowe oferty ocenia
AI według twoich preferencji, a te z dobrą oceną przychodzą na Discorda. Zbudowany na Laravel 13
i PHP 8.5, działa w Docker Compose na jednym serwerze.

## Jak to działa

1. **Wyszukiwanie.** Zapisujesz kryteria: miasto, wynajem albo sprzedaż, cena, metraż, liczba
   pokoi, opcjonalnie dzielnice i opis tego, czego szukasz (np. „najchętniej osiedle Projektant”).
2. **Pobieranie.** Scheduler co minutę sprawdza, które wyszukiwania czekają dłużej niż
   `SCRAPE_INTERVAL_MINUTES`, i pobiera z obu portali najnowsze oferty. Między zapytaniami robi
   przerwy, a gdy portal blokuje (403 albo 429), odpuszcza go na 2 godziny.
3. **Duplikaty.** To samo mieszkanie na kilku portalach zapisuje się jako jedna oferta. Jedna oferta
   pasująca do kilku wyszukiwań przychodzi raz.
4. **Ocena AI.** Każda nowa oferta dostaje ocenę od 1 do 10, pełny koszt miesięczny (najem,
   czynsz, opłaty z opisu) i oceny cząstkowe: koszt, lokalizacja, standard, dopasowanie.
5. **Powiadomienia.** Oferta z oceną od progu wyszukiwania (domyślnie 6) idzie na Discorda jako
   osobna wiadomość. Pierwszy przebieg i przebiegi z dużą liczbą nowych ofert dają jedno
   podsumowanie. Obniżka ceny już wysłanej oferty też daje wiadomość.

Szczegóły (architektura, model danych, scrapery, prompt AI) są w [`.planning`](.planning/README.md).
Postęp prac i podjęte decyzje: [`.planning/STATUS.md`](.planning/STATUS.md).

## Co jest w środku

| Usługa | Rola |
|---|---|
| `app` | PHP-FPM: panel (Filament) i API (Sanctum) |
| `worker` | kolejki `notify`, `scrape`, `default`: pobieranie portali i wysyłka |
| `worker-ai` | kolejka `ai`, domyślnie 3 kopie (`AI_WORKERS`): ocena ofert |
| `scheduler` | `schedule:work`: uruchamia wyszukiwania, sprząta kolejki, sprawdza alerty |
| `postgres` | baza danych (PostgreSQL 17) |
| `redis` | kolejki, cache, blokady |
| `caddy` | serwer WWW przed `app`, filtr adresów IP, pliki `public/` z obrazu aplikacji |

## Uruchomienie

Potrzebujesz Dockera z Compose. Na serwerze z Debianem lub Ubuntu wystarczy oficjalne
repozytorium Dockera.

1. Sklonuj repozytorium i skopiuj ustawienia:

   ```bash
   git clone https://github.com/wiluszdamian/project-keys.git
   cd project-keys
   cp .env.example .env
   chmod 600 .env
   ```

2. Uzupełnij w `.env` co najmniej `DB_PASSWORD`, `DISCORD_WEBHOOK_URL` i `OPENCODE_GO_API_KEY`.
   Bez klucza AI oferty idą bez oceny. Opis zmiennych jest w `.env.example`.
3. Zbuduj obraz i wygeneruj klucz aplikacji. Wypisany klucz wklej do `.env` jako `APP_KEY`:

   ```bash
   docker compose build
   docker compose run --rm --no-deps app php artisan key:generate --show
   ```

4. Uruchom kontenery i przygotuj bazę:

   ```bash
   docker compose up -d
   docker compose exec app php artisan migrate --force
   ```

5. Sprawdź Discorda i dodaj pierwsze wyszukiwanie:

   ```bash
   docker compose exec app php artisan notify:test
   docker compose exec app php artisan search:create
   ```

6. Załóż konto do panelu:

   ```bash
   docker compose exec app php artisan make:filament-user
   ```

## Panel

Panel jest pod `/admin` (adres `/` przekierowuje do niego). Masz w nim:

- **Wyszukiwania:** dodawanie, edycja, wstrzymanie i uruchomienie od razu.
- **Oferty:** tabela z oceną AI, kosztem miesięcznym i filtrami. Strona oferty pokazuje ocenę
  AI z plusami i minusami, historię cen i opis. Możesz nadać status (ulubiona, kontakt,
  oglądanie, odrzucona) i dopisać notatkę. Odrzucone znikają z listy i nie dają powiadomień.
- **Przebiegi:** każde pobranie portalu ze statusem i błędem.
- **Asystent:** czat z AI o ofertach z bazy. Widzi twoje wyszukiwania, oferty, oceny AI, historię cen
  i ceny w okolicy (tylko odczyt). Odpowiada z linkami do ofert w panelu. Rozmowy są zapisywane.
- **Dzwoneczek:** nowe dobre oferty, obniżki cen i alerty pojawiają się w panelu od razu, niezależnie
  od tego, jak często idzie Discord.
- **Ustawienia:** wszystko, co jest w `.env` i configu: częstotliwość i tempo pobierania,
  lokalizacje miejscowości dla portali, powiadomienia (w tym jedno zbiorcze podsumowanie dziennie
  albo co kilka godzin osobno dla wynajmu i sprzedaży), webhooki Discorda, model i klucz AI. Wartość z panelu
  wygrywa z `.env`. Zapis restartuje workery.

> [!IMPORTANT]
> Bez domeny są dwie możliwości:
> - `CADDY_HTTP_BIND=127.0.0.1:8080` (domyślnie): nic nie jest dostępne z internetu, łączysz się
>   tunelem `ssh -L 8080:localhost:8080 <serwer>` i otwierasz `http://localhost:8080`.
> - `CADDY_HTTP_BIND=0.0.0.0:80`: panel i API są pod `http://<IP serwera>`, ale Caddy wpuszcza
>   tylko adresy z `PANEL_ALLOWED_IPS` (spacja między adresami). Pozostali dostają 403. Połączenie
>   nie jest szyfrowane, więc to rozwiązanie na chwilę, do czasu domeny.
>
> Z domeną ustaw `CADDY_DOMAIN`, `CADDY_HTTP_BIND=80` i `CADDY_HTTPS_BIND=443`, a Caddy sam
> pobierze certyfikat.

## Codzienna obsługa

Polecenia uruchamiasz w kontenerze `app`, np. `docker compose exec app php artisan search:list`.

| Polecenie | Co robi |
|---|---|
| `search:create` | Nowe wyszukiwanie (pyta o pola) |
| `search:list` | Lista wyszukiwań z ostatnim przebiegiem |
| `search:run {id}` | Uruchamia wyszukiwanie od razu |
| `search:pause {id}`, `search:resume {id}` | Wstrzymuje i wznawia wyszukiwanie |
| `scraper:probe {portal} {id}` | Pobiera oferty na żywo i wypisuje je, bez zapisu |
| `notify:test` | Wiadomość testowa na Discorda |
| `ai:usage` | Zużycie tokenów AI dziś i z 30 dni |
| `alerts:check` | Czy był udany przebieg w 3 godziny i ile błędów AI (scheduler robi to co godzinę) |
| `api:token {nazwa}` | Token do API, pokazywany raz |

API (wymaga tokenu w nagłówku `Authorization: Bearer …`):

| Endpoint | Opis |
|---|---|
| `GET /api/health` | Stan aplikacji, bez tokenu |
| `GET/POST/PUT/DELETE /api/searches` | Wyszukiwania |
| `POST /api/searches/{id}/run`, `/pause`, `/resume` | Uruchomienie, wstrzymanie, wznowienie |
| `GET /api/searches/{id}/listings` | Oferty wyszukiwania (`sort=newest\|score`, `min_score`) |
| `GET /api/listings/{id}` | Oferta z oceną AI i historią cen |
| `GET /api/scrape-runs` | Ostatnie przebiegi scraperów |

Pełny opis pól: [`.planning/API.md`](.planning/API.md).

## Gdy coś nie działa

- **Brak nowych wiadomości.** Sprawdź `search:list` (czy wyszukiwanie jest aktywne) i
  `GET /api/scrape-runs`. Status `blocked` znaczy, że portal odpowiedział 403 lub 429. Aplikacja
  wraca do niego sama po 2 godzinach.
- **Oferty bez oceny AI.** Sprawdź `ai:usage` i logi workera AI:
  `docker compose logs worker-ai --tail 100`. Każdy kontener ma swoje logi.
- **Portal zmienił stronę.** `scraper:probe otodom {id}` pokaże, czy parser coś odczytuje. Gdy
  nie, trzeba poprawić scraper w `app/Scrapers` i dane testowe w `tests/Fixtures`.

## Praca nad kodem

Jak wprowadzać zmiany, uruchamiać testy i wdrażać: [CONTRIBUTING.md](CONTRIBUTING.md).
