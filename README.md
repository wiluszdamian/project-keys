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
Nieruchomości-online, Domiporta, Adresowo) i strony biur Stoprocent Nieruchomości, Rzeszowskie
Nieruchomości i Pierwsze Piętro według zapisanych wyszukiwań. Nowe oferty ocenia
AI według twoich preferencji, a te z dobrą oceną przychodzą na Discorda. Zbudowany na Laravel 13
i PHP 8.5, działa w Docker Compose na jednym serwerze.

## Jak to działa

1. **Wyszukiwanie.** Zapisujesz kryteria: miasto, wynajem albo sprzedaż, cena, metraż, liczba
   pokoi, opcjonalnie promień wokół miasta (np. Rzeszów + 10 km), dzielnice i opis tego, czego
   szukasz (np. „najchętniej osiedle Projektant”).
   Przy sprzedaży można też wybrać rynek (wtórny albo pierwotny), tylko mieszkania gotowe do
   zamieszkania (bez stanu deweloperskiego, wykończenia i remontu, meble niewymagane) i pomijanie
   ofert z wizualizacjami. Te filtry odrzucają tylko oferty, o których wiadomo, że nie pasują (z danych
   portalu albo z opisu). Gdy ogłoszenie nie mówi nic o stanie, decyduje ocena AI. Lista ofert i mapa
   pokazują tylko oferty, które pasują do jakiegoś wyszukiwania.
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
| `backup` | codzienna kopia bazy (`pg_dump`), test przywracania, opcjonalnie kopia do Cloudflare R2 |
| `osrm-car`, `osrm-bike` | trasy i czasy dojazdu (OSRM z mapą regionu) |
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

6. Przygotuj dane tras do liczenia dojazdów (mapa Podkarpacia, około 4 minut):

   ```bash
   scripts/osrm-prepare.sh
   docker compose up -d
   ```

7. Załóż konto do panelu:

   ```bash
   docker compose exec app php artisan make:filament-user
   ```

## Wdrożenie

Push na `main`: GitHub Actions uruchamia testy (`tests.yml`), a gdy przejdą, `deploy.yml` wdraża ten commit
na serwer przez `scripts/deploy.sh` (rsync, budowa obrazów, migracje, restart, sprawdzenie `/up`). Ręcznie:
zakładka Actions, „Deploy”, „Run workflow”, albo `scripts/deploy.sh --tests` z laptopa. Sekrety repozytorium:
`DEPLOY_SSH_KEY` (osobny klucz tylko do wdrożeń), `DEPLOY_HOST`, `DEPLOY_KNOWN_HOSTS`.

## Logowanie do panelu

Najpierw hasło przeglądarki (Caddy), potem passkey: przycisk „Zaloguj passkey” (odcisk palca, Face ID
albo klucz). Do panelu wchodzi tylko konto administratora. Konto bez passkey loguje się loginem, hasłem
i kodem 2FA z aplikacji, a panel od razu kieruje je do profilu, żeby dodało passkey. Konto z passkey
nie zaloguje się już hasłem. Polecenia przez SSH (`docker compose exec app php artisan ...`):

- `user:create {login} --admin` zakłada konto administratora,
- `user:reset-passkeys {login}` usuwa passkeys (np. po zgubieniu telefonu), potem logowanie hasłem i 2FA,
- `user:reset-2fa {login}` wyłącza 2FA z aplikacji.

## Panel

Panel jest pod `/admin` (adres `/` przekierowuje do niego). Masz w nim:

- **Wyszukiwania:** dodawanie, edycja, wstrzymanie i uruchomienie od razu.
- **Oferty:** tabela z oceną AI, kosztem miesięcznym i filtrami. Na telefonie i tablecie każda oferta
  jest kartą (zdjęcie, ocena, cena, metraż, status, źródło), od 1280 px zwykłą tabelą. Etykiety
  w kolumnie Źródło pokazują portale i biura, na których jest ta oferta. Kolumny dojazdu, kosztu
  i portalu pokazują się na szerokim ekranie albo po włączeniu w ikonie kolumn. Strona oferty zaczyna się
  od oceny AI, ceny i metrażu, niżej jest ocena AI z plusami i minusami, ceny transakcyjne, dojazd,
  historia cen i opis. Status (ulubiona, kontakt, oglądanie, odrzucona) i notatkę zmieniasz przyciskami
  na górze strony oferty albo ikoną przy ofercie w tabeli. Odrzucone znikają z listy i nie dają powiadomień.
- **Okazje:** zakładka na liście ofert (i pozycja w menu). Okazja to oferta o co najmniej 10% tańsza
  za m² niż transakcje z RCN w okolicy, o 15% tańsza za m² niż mediana ofert o podobnym metrażu w dzielnicy albo mieście (przy wynajmie
  25%), albo po obniżce ceny o co najmniej 5%. Tylko oferty z oceną AI od 6/10. Co godzinę `deals:refresh` przelicza okazje i wysyła nowe na Discorda
  i do dzwoneczka (kilka naraz jako jedno podsumowanie). Progi w Ustawieniach.
- **Zdjęte oferty:** co 30 minut `listings:check-active` otwiera strony ogłoszeń, których od 6 godzin
  nie było w wynikach (aktywne co najwyżej raz na dobę). Zdjęte z portalu (410, 404, „Ogłoszenie archiwalne”,
  „wygasło”, przekierowanie na stronę główną) dostają etykietę „Zdjęta”, przestają być okazjami i nie idą do
  oceny AI. Mieszkanie liczy się jako zdjęte, gdy nie ma go na żadnym portalu. Lista ofert domyślnie je ukrywa
  (filtr „Zdjęte z portali”). Gdy zniknie oferta ulubiona, z kontaktem albo oglądaniem, przychodzi powiadomienie
  w dzwoneczku. Oferta, która wróci do wyników, znów jest aktywna.
- **Warunki najmu:** przy ofercie wynajmu sekcja z rodzajem umowy (zwykła, najem okazjonalny,
  instytucjonalny, na doby), okresem najmu, kaucją, terminem, zwierzętami, ogłoszeniodawcą (osoba prywatna
  albo nazwa biura) i prowizją. Dane z portalu, a gdy ich brak, z opisu. „Nie podano” znaczy, że warto zapytać.
  Na liście ofert pod tytułem jest skrót, np. „prywatnie · okazjonalny · min. 12 mies.”.
- **Jak długo wisi:** przy ofercie widać, od kiedy jest na rynku (data dodania z portalu, a gdy jej nie ma,
  pierwsze zobaczenie u nas) i po ilu dniach schodzą podobne zdjęte oferty. Oferta wisząca dłużej niż
  3/4 podobnych (najmniej 14 dni; bez danych 30 dni przy najmie i 90 przy sprzedaży) dostaje etykietę
  „Wisi N dni”: jest miejsce na negocjację. Oferta znaleziona w pierwszym przebiegu portalu mogła wisieć
  wcześniej, więc wtedy panel pisze „co najmniej”. To samo widzi ocena AI, asystent i sobotni raport
  (ile ofert zeszło w tygodniu, po ilu dniach, które wiszą najdłużej).
- **Oglądanie:** menu „Oglądanie” na stronie oferty: termin (status zmienia się na Oglądanie, pobiera się
  plik .ics do kalendarza z przypomnieniem godzinę wcześniej), pytania od AI, „Po oglądaniu” (wrażenia
  trafiają do notatki, z której uczy się AI, i od razu można zmienić status) i odwołanie terminu. Sekcja
  „Oglądanie” na stronie oferty ma pytania od AI, pytania o to, czego ogłoszenie nie mówi (czynsz, kaucja,
  ogrzewanie, winda, uwagi z oceny AI, sygnały ryzyka) i stałą listę rzeczy do sprawdzenia na miejscu.
  AI przygotowuje pytania samo przy zmianie statusu na Oglądanie. Na 2 godziny przed terminem przychodzi
  przypomnienie w dzwoneczku. Zakładka „Oglądania” na liście ofert pokazuje umówione terminy.
- **Nauka na decyzjach:** oferty oznaczone jako ulubiona, kontakt, oglądanie albo odrzucona, razem
  z notatkami, trafiają do oceny AI jako przykłady tego, czego szukasz. Przy odrzuceniu i dodaniu do
  ulubionych możesz wpisać powód, który zapisuje się w notatce.
- **Strona oferty:** etykiety (garaż lub miejsce postojowe i komórka lub piwnica: w cenie albo za dopłatą,
  ogrzewanie, rok budowy, winda, balkon), sekcja „Mieszkanie i budynek” (z danych portalu, a gdy ich brak,
  z opisu) i „Infrastruktura”: najbliższe sklepy (po jednym z każdej sieci: Biedronka, Lidl, Kaufland,
  Auchan…), żłobek, przedszkole, szkoła podstawowa i średnia, przychodnia z pediatrą, przychodnia POZ i
  Szpital Miejski przy Rycerskiej, z odległością drogą i czasem pieszo, rowerem i autem.
  Punkty z OpenStreetMap pobiera `pois:import` raz w miesiącu, odległości liczy nasz OSRM. Okolica trafia
  też do oceny AI i do asystenta.
- **Strona główna:** liczby i skróty (nowe oferty, okazje, moje oferty, wyszukiwania, mapa, kredyt,
  asystent, raport). Listę ofert ma zakładka Oferty (z zakładkami Okazje i Moje).
- **Przebiegi:** każde pobranie portalu ze statusem i błędem.
- **Ceny transakcyjne:** każda oferta sprzedaży jest porównana z prawdziwymi cenami sprzedaży mieszkań
  z Rejestru Cen Nieruchomości (GUGiK): transakcje w promieniu 800 m do 3 km, podobny metraż, ostatnie
  2 lata, ten sam rynek (pierwotny albo wtórny), gdy portal go podaje. Przy ofercie widać, czy cena jest
  rynkowa, o ile drożej albo taniej, i najbliższe transakcje. Wynik trafia też do oceny AI, na Discorda
  i do sobotniego raportu.
- **Mapa:** oferty na mapie w kolorze oceny, twoje miejsca dojazdu, filtry.
- **Miejsca dojazdu:** np. praca. Z każdej oferty liczę dojazd autem i rowerem (własny serwer tras OSRM).
  Czasy widać przy ofercie, można po nich filtrować, a AI bierze je pod uwagę w ocenie.
- **Asystent:** czat z AI o ofertach z bazy. Widzi Twoje wyszukiwania, oferty ze wszystkich portali i biur,
  oceny AI, okazje, czynsz (także z opisu), historię cen i ceny w okolicy, liczy kredyt RKM. Na Twoją prośbę
  ustawia status oferty i dopisuje notatkę, poza tym nic nie zmienia. Odpowiada z linkami do ofert w panelu.
  Rozmowy można przeszukiwać, zmieniać im nazwę, pobrać jako Markdown i usuwać (pojedynczo albo wszystkie).
  Długie czekanie można przerwać, a po błędzie ponowić pytanie.
- **Raport rynku:** w każdą sobotę o 20:00 raport z ostatnich 7 dni, osobno dla wynajmu i sprzedaży:
  liczba ofert, nowe, mediany cen z porównaniem do poprzedniego tygodnia, dzielnice, pokoje, obniżki
  i najlepsze nowe oferty. Idzie na Discorda i do panelu. W panelu jest też podgląd na żywo.
- **Kredyt:** symulacja Rodzinnego Kredytu Mieszkaniowego (gwarancja BGK zamiast wkładu własnego).
  Liczy kwotę kredytu, gwarancję, potrzebny wkład (do 500 000 zł zero, powyżej różnica, bo gwarancja to
  najwyżej 100 000 zł), ratę przy stałym oprocentowaniu przez 5 lat albo zmiennym, ratę po wzroście stóp,
  limit ceny za m² z tabeli BGK i spłatę rodzinną przy drugim i trzecim dziecku. Przycisk „Policz kredyt”
  na stronie oferty sprzedaży wypełnia dane z oferty. Sama strona oferty sprzedaży ma sekcję Kredyt:
  rata przy stałym i zmiennym oprocentowaniu, kwota kredytu, wkład, odsetki i limit ceny za m². BGK i
  POLSTR/WIBOR nie mają darmowego API, więc stawki ustawiasz w Ustawieniach (zakładka Kredyt). Stopa
  referencyjna NBP pobiera się sama i gdy się zmieni, strona prosi o aktualizację stawek.
- **Logi:** wpisy z logów aplikacji, workerów i schedulera z filtrem poziomu i wyszukiwaniem, pobranie
  pliku i lista nieudanych zadań kolejki.
- **Dzwoneczek:** nowe dobre oferty, obniżki cen, raport rynku i alerty pojawiają się w panelu od razu,
  niezależnie od tego, jak często idzie Discord. Alerty systemowe (blokada portalu, brak przebiegów,
  błędy AI) idą tylko do panelu, chyba że włączysz Discorda w ustawieniach.
- **Ustawienia:** wszystko, co jest w `.env` i configu: częstotliwość i tempo pobierania,
  lokalizacje miejscowości dla portali, powiadomienia (w tym jedno zbiorcze podsumowanie dziennie
  albo co kilka godzin osobno dla wynajmu i sprzedaży), webhooki Discorda, model i klucz AI. Wartość z panelu
  wygrywa z `.env`. Zapis restartuje workery.

Logowanie wymaga hasła i kodu z aplikacji 2FA (Google Authenticator, Aegis, 1Password).
Przy pierwszym wejściu panel pokaże kod QR i kody zapasowe. Zapisz kody zapasowe: każdy działa raz,
gdy nie masz telefonu. Gdy zgubisz telefon i kody, wyłącz 2FA na serwerze:
`docker compose exec app php artisan user:reset-2fa twoj@email`.

> [!IMPORTANT]
> Bez domeny są dwie możliwości:
> - `CADDY_HTTP_BIND=127.0.0.1:8080` (domyślnie): nic nie jest dostępne z internetu, łączysz się
>   tunelem `ssh -L 8080:localhost:8080 <serwer>` i otwierasz `http://localhost:8080`.
> - `CADDY_HTTP_BIND=0.0.0.0:80`: panel i API są pod `http://<IP serwera>`, ale Caddy wpuszcza
>   tylko adresy z `PANEL_ALLOWED_IPS` (spacja między adresami). Pozostali dostają 403. Połączenie
>   nie jest szyfrowane, więc to rozwiązanie na chwilę, do czasu domeny.
>
> Z domeną ustaw `CADDY_DOMAIN`, `CADDY_HTTP_BIND=80` i `CADDY_HTTPS_BIND=443`, a Caddy sam
> pobierze certyfikat. Domena jest dostępna z każdego adresu i od razu pokazuje stronę logowania
> (login + passkey). API wymaga tokenu.

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
| `alerts:check` | Czy był udany przebieg w 3 godziny, ile błędów AI i czy jest świeża kopia bazy (scheduler robi to co godzinę) |
| `reports:weekly-market` | Raport rynku z 7 dni (scheduler: soboty 20:00). `--no-send` tylko zapisuje |
| `user:reset-2fa {email}` | Wyłącza 2FA konta, np. po utracie telefonu |
| `rcn:import` | Pobiera ceny transakcyjne z RCN i porównuje oferty sprzedaży (scheduler: niedziele 4:30). `--compare-only` bez pobierania |
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
| `GET/PUT /api/notification-settings` | Zbiorcze powiadomienia kanałów, raport rynku, alerty na Discordzie |
| `GET /api/market-reports` | Raporty rynku (`deal_type=rent\|sale`, `limit`) |

Pełny opis pól: [`.planning/API.md`](.planning/API.md).

## Kopia bazy

Kontener `backup` codziennie o 3:00 (`BACKUP_HOUR`, czas polski) robi `pg_dump` bazy do wolumenu
`backups` i sprawdza, czy plik da się odczytać. Trzyma 7 kopii dziennych, 4 tygodniowe (z niedziel)
i 6 miesięcznych (z 1. dnia miesiąca). Raz w tygodniu przywraca najnowszą kopię do tymczasowej bazy
w tym samym kontenerze i porównuje liczbę wierszy z bazą produkcyjną. Produkcji to nie zmienia.
Po pierwszym uruchomieniu kontener robi kopię po minucie.

Stan (ostatnia kopia, rozmiar, test przywracania) jest na stronie głównej panelu w kafelku „Kopia
bazy”. Gdy kopia jest starsza niż 26 godzin, nie wyszła albo test przywracania się nie udał,
`alerts:check` wysyła alert do panelu. Logi: `docker compose logs backup`.

Na serwerze, w `~/project-keys`:

| Polecenie | Co robi |
|---|---|
| `scripts/restore.sh --list` | Lista kopii i stan |
| `scripts/restore.sh --test [plik]` | Test przywracania (domyślnie najnowszej kopii) |
| `docker compose exec backup backup-now [etykieta]` | Kopia od razu, do `manual/` (trzyma 5 ostatnich) |
| `scripts/restore.sh daily/pk-20261124-030000.dump` | Przywraca kopię do bazy produkcyjnej |
| `scripts/restore.sh --from ~/pk.dump` | Przywraca kopię z pliku na serwerze, np. przegranego z laptopa |

Przywracanie najpierw sprawdza kopię w tymczasowej bazie, prosi o wpisanie `TAK`, robi kopię
bezpieczeństwa obecnej bazy (`manual/...-przed-przywroceniem.dump`), zatrzymuje aplikację,
przywraca bazę w jednej transakcji, uruchamia migracje i startuje aplikację z powrotem.

Najnowsza kopia na laptopa:

```bash
ssh project-keys 'cd project-keys && docker compose exec -T backup backup-cat' > project-keys.dump
# konkretny plik: ... backup-cat weekly/pk-20261122-030000.dump
```

Na serwer z powrotem: `scp project-keys.dump project-keys:` i `scripts/restore.sh --from ~/project-keys.dump`.

**Kopia poza serwerem (Cloudflare R2).** W Cloudflare: R2, nowy bucket, potem token API R2 z prawem
„Object Read & Write” tylko do tego bucketu. W `.env` na serwerze uzupełnij `BACKUP_R2_ACCESS_KEY_ID`,
`BACKUP_R2_SECRET_ACCESS_KEY`, `BACKUP_R2_ENDPOINT` (`https://<ID konta>.r2.cloudflarestorage.com`)
i `BACKUP_R2_BUCKET`, potem `docker compose up -d backup`. Każda kopia trafia wtedy też do R2
(katalog `BACKUP_R2_PATH`), a pliki starsze niż 200 dni są tam usuwane. Od razu sprawdzisz to przez
`docker compose exec backup backup-now` i `scripts/restore.sh --list` (pole `remote_ok_at`).

**`.env` nie ma w kopii.** Trzymaj go osobno, np. w menedżerze haseł. Bez tego samego `APP_KEY`
przywrócona baza działa, ale sekrety 2FA i inne zaszyfrowane pola nie dadzą się odczytać.

## Gdy coś nie działa

- **Brak nowych wiadomości.** Sprawdź `search:list` (czy wyszukiwanie jest aktywne) i
  `GET /api/scrape-runs`. Status `blocked` znaczy, że portal odpowiedział 403 lub 429. Aplikacja
  wraca do niego sama po 2 godzinach.
- **Oferty bez oceny AI.** Sprawdź `ai:usage` i stronę Logi w panelu (logi app, workerów i
  schedulera są we wspólnym wolumenie `logs`). Wyjście samych procesów:
  `docker compose logs worker-ai --tail 100`.
- **Portal zmienił stronę.** `scraper:probe otodom {id}` pokaże, czy parser coś odczytuje. Gdy
  nie, trzeba poprawić scraper w `app/Scrapers` i dane testowe w `tests/Fixtures`.

## Praca nad kodem

Jak wprowadzać zmiany, uruchamiać testy i wdrażać: [CONTRIBUTING.md](CONTRIBUTING.md).
