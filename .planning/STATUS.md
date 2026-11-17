# Status

Aktualizuje Claude Code po każdej fazie.

## Serwer

- Dostawca: Contabo
- Adres: 155.133.197.67 (alias SSH `project-keys`, użytkownik `deploy`, klucz `~/.ssh/id_ed25519_project_keys`)
- CPU / RAM / dysk: 4 vCPU AMD EPYC, 7.8 GB RAM, 99 GB dysk, swap 2 GB
- System: Debian 13 (trixie), kernel 6.12
- Docker / Compose: Docker 29.10.0, Compose v5.8.0
- Domena: na razie brak, API przez tunel `ssh -L 8080:localhost:8080 project-keys`

## Postęp

| Faza | Status | Data | Uwagi |
|---|---|---|---|
| 0 Serwer | gotowe | 2026-11-17 | SSH tylko kluczem jako deploy, ufw 22/80/443, fail2ban, unattended-upgrades, Docker |
| 1 Szkielet | gotowe | 2026-11-17 | Laravel 13.43, PHP 8.5.12, 6 usług w compose, `/api/health` ok przez Caddy, Pest 4 |
| 2 Dane i API | gotowe | 2026-11-17 | 6 tabel, Sanctum 4.5, API wyszukiwań, ofert i przebiegów, limit 60/min na token, 29 testów |
| 3 Otodom | gotowe | 2026-11-17 | Dane z `__NEXT_DATA__`, 36 ofert na stronę, sprawdzone na żywo dla Rzeszowa, 66 testów |
| 4 OLX | gotowe | 2026-11-17 | Endpoint JSON OLX, duplikaty po adresie i po cenie z metrażem, HTTP/2 (decyzja właściciela), 92 testy. Na żywo: 153 oferty, 33 duplikaty |
| 5 Harmonogram | gotowe | 2026-11-17 | Pierwszy automatyczny przebieg 21:13: Otodom 109, OLX 120 ofert. Testy na Postgresie (`scripts/test.sh`) |
| 6 AI | do zrobienia | | |
| 7 Powiadomienia | w trakcie | 2026-11-17 | Discord gotowy (przed fazą 6, na prośbę właściciela). Telegram do zrobienia |
| 8 Utrwalenie | do zrobienia | | |

## Decyzje

(data, decyzja, powód)

- 2026-11-17: PHP 8.5 i najnowszy Laravel zamiast PHP 8.4. Prośba właściciela.
- 2026-11-17: Serwer ma Debian 13 zamiast Ubuntu 24.04. Docker z oficjalnego repozytorium dla Debiana, reszta kroków bez zmian.
- 2026-11-17: `deploy` ma sudo bez hasła (NOPASSWD), bo logowanie jest tylko kluczem i użytkownik nie ma hasła.
- 2026-11-17: Ustawienia SSH w `/etc/ssh/sshd_config.d/00-hardening.conf`, usunięty `50-cloud-init.conf`, który włączał logowanie hasłem.

- 2026-11-17: Kod na serwerze w `/home/deploy/project-keys` (nazwa jak repo) zamiast `/home/deploy/mieszkania`. W trakcie pracy kod trafia na serwer przez rsync, `deploy.sh` z `git pull` w fazie 8.
- 2026-11-17: Bez frontendu. Usunięte pliki Vite i npm ze szkieletu Laravela, bo aplikacja ma tylko API.
- 2026-11-17: Caddy bez domeny słucha na `127.0.0.1:8080`. Po ustawieniu domeny wystarczy zmienić `CADDY_DOMAIN`, `CADDY_HTTP_BIND=80`, `CADDY_HTTPS_BIND=443` w `.env`.
- 2026-11-17: Obraz z paczkami dev (`INSTALL_DEV=1` w `.env`) na serwerze deweloperskim, żeby testy szły w kontenerze. Na produkcji `INSTALL_DEV=0`.
- 2026-11-17: Jeden użytkownik `owner@localhost` z losowym hasłem. Tworzy go `api:token` przy pierwszym tokenie, logowanie tylko tokenem.
- 2026-11-17: `search_listing` ma własną kolumnę `id` (łatwiej z modelem pivot). `scrape_runs.status` ma dodatkowo wartość `running` na czas przebiegu.
- 2026-11-17: Testy działają na SQLite w pamięci. Zapytania na Postgresie sprawdzone ręcznie przez API na serwerze.
- 2026-11-17: Otodom: miasto zamieniane na lokalizację z mapy w `config/scrapers.php` (`otodom.locations`). Sprawdzone: Kraków, Warszawa, Wrocław, Gdańsk, Poznań, Łódź, Rzeszów. Nowe miasto trzeba dopisać ręcznie.
- 2026-11-17: Otodom: dzielnice filtrowane po pobraniu w `ListingMatcher`, nie w adresie. Co 20 minut 3 strony najnowszych ofert z całego miasta wystarczą, żeby złapać nowe.
- 2026-11-17: Otodom przyjmuje w filtrze pokoi tylko ONE do FIVE. Gdy zakres wychodzi poza 5, filtr pokoi jest tylko po stronie aplikacji.
- 2026-11-17: Lista wyników Otodom nie zawiera opisu (`shortDescription` jest pusty). Na razie `description` zostaje puste. Czy pobierać stronę oferty dla nowych ogłoszeń, zdecydujemy w fazie 6 (AI).
- 2026-11-17: Ogłoszenie bez dzielnicy jest odrzucane, gdy wyszukiwanie ma listę dzielnic. Brak ceny, metrażu lub pokoi nie odrzuca oferty.
- 2026-11-17: OLX: endpoint JSON `/api/v1/offers`, z którego korzysta sama strona (strona HTML waży 4,4 MB). Identyfikatory kategorii i miasta z `/api/v1/friendly-links/query-params/...`, zapamiętane na 30 dni, więc nie trzeba ręcznej mapy miast. Lista OLX zawiera opis oferty.
- 2026-11-17: OLX: dane ogłoszeniodawcy (`user`, `contact`) nie trafiają do bazy ani do fixtures.
- 2026-11-17: Duplikaty: kolumna `listings.duplicate_of_id`. Najpierw dokładnie po adresie (kopie z Otodom na OLX mają `external_url`), potem po cenie (±2%) i metrażu (±1 m2) w tym samym mieście, z ostatnich 7 dni. Usługa `DuplicateDetector`.
- 2026-11-17: Decyzja właściciela: zapytania do portali idą przez HTTP/2. OLX (CloudFront) odpowiadał 403 na HTTP/1.1 z nagłówkiem przeglądarki, a przepuszczał HTTP/2. To odstępstwo od zasady „bez obchodzenia zabezpieczeń” z PROJECT.md i SCRAPERS.md, przyjęte świadomie przez właściciela. Pozostałe zasady bez zmian: przerwy, maks. 3 strony, wstrzymanie na 2 h po 403/429.
- 2026-11-17: Osiedle Projektant (Rzeszów) nie istnieje na Otodom jako lokalizacja. W wyszukiwaniu #3 jest w preferencjach AI, nie w filtrze dzielnic.
- 2026-11-17: Testy idą na SQLite, a SQLite nie pilnuje typów kolumn. Błąd z ceną (ułamek porównywany z kolumną integer) wyszedł dopiero na Postgresie. Warto przenieść testy na Postgres (osobna baza testowa w compose).
- 2026-11-17: Testy przeniesione na Postgres: baza `mieszkania_test` i użytkownik `test` w kontenerze postgres, uruchamiane przez `scripts/test.sh`. `TestCase` przerywa testy, jeśli baza nie jest testowa, i blokuje prawdziwe zapytania HTTP.
- 2026-11-17: Scheduler co minutę uruchamia `search:dispatch-due`, które dodaje do kolejki wyszukiwania czekające dłużej niż `SCRAPE_INTERVAL_MINUTES`. Przebieg ręczny (`search:run`, API) nie przesuwa harmonogramu w dziwny sposób, bo liczy się `last_run_at`.
- 2026-11-17: Jeden job naraz na portal (`WithoutOverlapping` po portalu) plus blokada w `HttpFetcher` na każde zapytanie. `REDIS_QUEUE_RETRY_AFTER` 960 s, bo job scrapera może trwać do 10 minut.
- 2026-11-17: Powiadomienia wysyłane po całym przebiegu (wszystkie portale), nie po każdym portalu. Dzięki temu pierwszy przebieg daje jedno podsumowanie z obu portali, a próg `NOTIFY_DIGEST_THRESHOLD` liczy oferty z całego przebiegu.
- 2026-11-17: Discord zrobiony przed fazą 6. Do czasu oceny AI wiadomość pokazuje początek opisu z ogłoszenia, a próg `min_score_to_notify` nie działa (każda nowa oferta jest wysyłana).
- 2026-11-17: Duplikaty z drugiego portalu są dopasowane do wyszukiwania, ale z `notify_skipped_reason = duplicate`, bez powiadomienia.

## Otwarte pytania

- Czy warunki OpenCode Go pozwalają na użycie z własnej aplikacji?
- Dokładne ID modelu DeepSeek w OpenCode Go.
- Domena dla HTTPS.
- Duplikaty po cenie i metrażu mogą się mylić: dwa różne ogłoszenia OLX (2400 zł, 63 m2) wskazały tę samą ofertę Otodom (2390 zł, 63,9 m2). Można zaostrzyć regułę, np. wymagać tej samej liczby pokoi.
