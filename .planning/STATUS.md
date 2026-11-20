# Status

Aktualizuje Claude Code po każdej fazie.

## Serwer

- Dostawca: Contabo
- Adres: 155.133.197.67 (alias SSH `project-keys`, użytkownik `deploy`, klucz `~/.ssh/id_ed25519_project_keys`)
- CPU / RAM / dysk: 4 vCPU AMD EPYC, 7.8 GB RAM, 99 GB dysk, swap 2 GB
- System: Debian 13 (trixie), kernel 6.12
- Docker / Compose: Docker 29.10.0, Compose v5.8.0
- Domena: `https://nieruchomosci.wilusz.tech` (proxy Cloudflare). Panel z 2FA. Panel i API tylko z IP właściciela (`PANEL_ALLOWED_IPS`), z innych adresów 403. Zapasowo `http://155.133.197.67` (to samo ograniczenie) i tunel `ssh -L 8080:localhost:80 project-keys`

## Postęp

| Faza | Status | Data | Uwagi |
|---|---|---|---|
| 0 Serwer | gotowe | 2026-11-17 | SSH tylko kluczem jako deploy, ufw 22/80/443, fail2ban, unattended-upgrades, Docker |
| 1 Szkielet | gotowe | 2026-11-17 | Laravel 13.43, PHP 8.5.12, 6 usług w compose, `/api/health` ok przez Caddy, Pest 4 |
| 2 Dane i API | gotowe | 2026-11-17 | 6 tabel, Sanctum 4.5, API wyszukiwań, ofert i przebiegów, limit 60/min na token, 29 testów |
| 3 Otodom | gotowe | 2026-11-17 | Dane z `__NEXT_DATA__`, 36 ofert na stronę, sprawdzone na żywo dla Rzeszowa, 66 testów |
| 4 OLX | gotowe | 2026-11-17 | Endpoint JSON OLX, duplikaty po adresie i po cenie z metrażem, HTTP/2 (decyzja właściciela), 92 testy. Na żywo: 153 oferty, 33 duplikaty |
| 5 Harmonogram | gotowe | 2026-11-17 | Pierwszy automatyczny przebieg 21:13: Otodom 109, OLX 120 ofert. Testy na Postgresie (`scripts/test.sh`) |
| 6 AI | gotowe | 2026-11-17 | 143 testy. Prawdziwe wywołanie: 12,7 s, ok. 1750 tokenów na ofertę. AI włączone na serwerze |
| 7 Powiadomienia | w trakcie | 2026-11-17 | Discord gotowy (przed fazą 6, na prośbę właściciela). Telegram do zrobienia |
| 8 Utrwalenie | w trakcie | 2026-11-20 | `deploy.sh`, logi w panelu. Backup do zrobienia |

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
- 2026-11-17: AI przez `laravel/ai` v1.4.1, provider `opencode` (sterownik `openai-compatible`), adres `https://opencode.ai/inference/go/openai/v1`, model `deepseek-v4.1-flash` (lista z `/inference/go/v1/models`). Nagłówki wg dokumentacji Go: własny User-Agent `project-keys/1.0` i stały `x-opencode-session`.
- 2026-11-17: Odpowiedź modelu rozszerzona na prośbę właściciela: `monthly_cost` (najem + czynsz + inne opłaty z opisu) i `sub_scores` (koszt, lokalizacja, standard, dopasowanie). Nowe kolumny w `listing_analyses`.
- 2026-11-17: Ocena idzie w łańcuchu przed powiadomieniem (najpierw `AnalyzeListingJob` dla nowych ofert, potem wysyłka). Błąd AI nie blokuje powiadomienia: oferta idzie z dopiskiem „brak oceny AI”.
- 2026-11-17: Pusta wartość w `.env` (np. `OPENCODE_GO_MODEL=`) liczy się jak brak, wtedy działa wartość domyślna.
- 2026-11-17: Właściciel włączył region Global w OpenCode dla DeepSeek.
- 2026-11-17: Prompt: nazwa osiedla w tytule lub opisie liczy się bardziej niż dzielnica z portalu. Bez tej zasady model uznał ofertę „OS. PROJEKTANT” za spoza osiedla, bo Otodom podał dzielnicę Franciszka Kotuli.
- 2026-11-17: Otodom: lista wyników nie ma opisu ani często czynszu. Przed oceną AI nowa oferta z Otodom pobiera raz stronę ogłoszenia (`EnrichesListings`): opis, czynsz, kaucja, rok budowy, ogrzewanie. Kolejne przebiegi nie kasują tych danych.
- 2026-11-17: Pola kosztu w odpowiedzi AI są wymagane. Pusty koszt przy ofercie z ceną to błędna odpowiedź i ponowienie (model raz zwrócił „placeholder”).
- 2026-11-17: Discord: pole „Najem: 2 900 zł + 570 zł czynsz”. Czynsz z portalu albo z opisu (przez AI), a gdy nieznany, napis „czynsz nieznany”. Prośba właściciela.
- 2026-11-17: Wyszukiwania na sprzedaż (#4 do #7): mieszkania do 560 000 zł, od 3 pokoi, osobno dla Rzeszowa, Boguchwały, Tajęciny i Rudnej Małej (to osobne miejscowości na portalach). Wspólne preferencje AI: osiedle Projektant albo te miejscowości, dobry standard wykończenia. Lokalizacje Otodom dla trzech miejscowości sprawdzone na żywo.
- 2026-11-17: Osobne workery: `worker` (notify, scrape, default) i `worker-ai` w 3 kopiach (`AI_WORKERS`). Ocena idzie równolegle w batchu, a nie w łańcuchu. Wcześniej jeden worker oceniał 130 ofert po kolei (ok. 45 min) i w tym czasie nie pobierał portali.
- 2026-11-17: AI: `reasoning_effort=low` (`AI_REASONING_EFFORT`) i timeout 120 s. Przy domyślnym rozumowaniu część odpowiedzi trwała ponad 60 s i kończyła się błędem połączenia. Sprawdzenie pustego kosztu tylko dla wynajmu: przy sprzedaży koszt to sam czynsz i bywa nieznany.
- 2026-11-17: Podsumowanie pokazuje tylko oferty z oceną od `min_score_to_notify` (i bez oceny, gdy AI zawiodło). W nagłówku liczba wszystkich i liczba dobrych.
- 2026-11-18: Otodom: klucz oferty to kod z końca adresu (np. `ID4E1rD`), nie liczbowe `id`. Wyróżnione ogłoszenia mają tymczasowe id (np. 9000062325), wspólne dla różnych ofert. Aplikacja brała je za jedną ofertę, której cena skakała od 2400 do 554 894 zł, i co 20 minut wysyłała „obniżkę ceny”. Migracja przepisała klucze, oferty z id 9000… zostały ze starym kluczem.
- 2026-11-18: Jedna oferta idzie na Discorda raz, nawet gdy pasuje do kilku wyszukiwań albo ma duplikat z drugiego portalu (`notify_skipped_reason = already_sent`, bez drugiej oceny AI). Obniżka ceny idzie tylko przy nowej najniższej cenie i raz na ofertę.
- 2026-11-18: Panel Filament 5 pod `/admin`, prośba właściciela. Na razie bez domeny: port 80 otwarty (`CADDY_HTTP_BIND=0.0.0.0:80`), ale Caddy wpuszcza tylko `PANEL_ALLOWED_IPS` (IP właściciela) i sieci prywatne (tunel SSH). Port tylko na IPv4, żeby Docker nie podmieniał adresu klienta. Domena, HTTPS i 2FA później.
- 2026-11-18: Ustawienia z panelu (tabela `settings`) nadpisują config i `.env`. W bazie tylko wartości różne od domyślnych. Sekrety (webhooki, klucz AI) zaszyfrowane `APP_KEY` i nigdy nie wracają do formularza. Właściciel chciał mieć wszystko do ustawienia w panelu. Zapis robi `queue:restart`, bo workery czytają config przy starcie.
- 2026-11-18: Statusy ofert w panelu (ulubiona, kontakt, oglądanie, odrzucona) i notatka. Odrzucona nie daje powiadomień ani obniżek ceny.
- 2026-11-18: Caddy ma własny obraz (`target: web`) z `public/` skopiowanym z obrazu aplikacji. Filament publikuje CSS i JS przy budowaniu, więc pliki zawsze pasują do kodu.
- 2026-11-18: Powiadomienia ustawiane osobno dla każdego wyszukiwania (panel i API): włącz/wyłącz, kanały, próg oceny i częstotliwość: od razu, zbiorczo co N godzin albo raz dziennie o godzinie. W trybie zbiorczym dobre oferty dostają `notify_skipped_reason = scheduled` i czekają na `notify:send-scheduled` (co minutę). Pierwszy przebieg i obniżki cen idą od razu. Ocena AI działa także przy wyłączonych powiadomieniach, bo wyniki są w panelu.
- 2026-11-18: Lista ofert i strona główna panelu domyślnie pokazują tylko oferty z oceną od progu wyszukiwania (przełącznik w filtrach, zapamiętywany w sesji). Kolumna Wynajem/Sprzedaż.
- 2026-11-18: Sześć nowych portali na prośbę właściciela: Rzeszowiak, Morizon, Gratka, Nieruchomości-online, Domiporta, Adresowo (opis w SCRAPERS.md). Wszystkie z `EnrichesListings` (strona ogłoszenia przed oceną AI). Mapy lokalizacji dla Morizon, Gratki, Domiporty i Adresowa w config i w panelu. Filtry i sortowanie zabronione w robots.txt (Morizon, Gratka, Adresowo) zastępuje filtrowanie po pobraniu.
- 2026-11-18: Gratka ma te same oferty i te same id co Morizon. Duplikaty łączymy dokładnie po `raw.network_id`. Gratki nie włączamy w wyszukiwaniach, bo podwaja zapytania bez nowych ofert.
- 2026-11-18: Duplikaty po cenie i metrażu wymagają też tej samej liczby pokoi (gdy obie oferty ją podają). Przy ośmiu portalach fałszywe dopasowania byłyby częstsze.
- 2026-11-18: Domena `nieruchomosci.wilusz.tech` z proxy Cloudflare (ustawił właściciel). Caddy pobiera certyfikat Let's Encrypt (HTTP-01 przez Cloudflare), port 443 tylko IPv4. Adres klienta z `CF-Connecting-IP`, ale tylko gdy połączenie przychodzi z adresów Cloudflare (`trusted_proxies`), więc podrobiony nagłówek prosto na IP serwera nic nie daje. Panel dalej tylko z `PANEL_ALLOWED_IPS`. Blok `:80` po IP i tunel SSH zostają jako zapasowe wejście.
- 2026-11-18: Zbiorcze powiadomienia kanałów (prośba właściciela, za dużo wiadomości): osobno dla wynajmu i sprzedaży jedno podsumowanie co N godzin albo raz dziennie o godzinie, ze wszystkich wyszukiwań tego typu, razem z obniżkami cen. Ustawienie kanału wygrywa z ustawieniami wyszukiwań i obejmuje też pierwszy przebieg. Panel (Ustawienia → Powiadomienia) i API `/api/notification-settings`. Domyślnie `per_search`, czyli jak dotąd.
- 2026-11-18: Dzwoneczek w panelu (powiadomienia Filamenta w bazie, odświeżanie co 30 s): jedno powiadomienie na przebieg wyszukiwania z najlepszą nową ofertą, każda obniżka ceny i alerty systemowe. Od razu, niezależnie od Discorda.
- 2026-11-18: Asystent w panelu (prośba właściciela): czat z agentem `ApartmentAssistant` (ten sam model co oceny), narzędzia tylko do odczytu: `list_searches`, `find_listings`, `get_listings`, `market_stats`. Rozmowy w tabelach `agent_conversations` z laravel/ai, tytuł z pierwszego pytania (bez dodatkowego zapytania). Odpowiedź liczy `AssistantReplyJob` w kolejce `ai`, a strona odpytuje co 2 s, bo odpowiedź z narzędziami bywa dłuższa niż 100 s, po których Cloudflare zrywa połączenie.
- 2026-11-18: Czasy dojazdu (prośba właściciela): miejsca dojazdu w panelu i API (praca właściciela i żony). Trasy autem i rowerem z własnego OSRM v6 (mapa Podkarpacia z Geofabrik, `scripts/osrm-prepare.sh`), więc współrzędne nie wychodzą poza serwer. Adresy ofert bez pinezki i adresy miejsc szukamy w OpenStreetMap Nominatim (zgoda właściciela), 1 zapytanie na sekundę, wyniki w cache. Dokładność położenia w `geo_precision` (portal, street, district, city). Dojazd trafia do oceny AI, wiadomości na Discordzie, panelu (kolumna, filtr, sekcja oferty) i asystenta.
- 2026-11-18: Pinezki z portali: Otodom, Morizon, Gratka, Nieruchomości-online, Domiporta i Adresowo ze strony ogłoszenia. OLX podaje dokładną pinezkę tylko przy `show_detailed`, inaczej to środek okręgu kilku km, więc wtedy wolimy ulicę albo dzielnicę.
- 2026-11-18: Naprawiony błąd: strona ogłoszenia pobierała się tylko przy braku opisu (Otodom). Nowe portale mają krótki opis na liście, więc nie dostawały pełnych danych. Teraz raz dla każdej oferty (znacznik `raw.enriched_at`).
- 2026-11-18: Mapa w panelu: Leaflet 1.9.4 z unpkg i kafelki OpenStreetMap (ładowane przez przeglądarkę właściciela).
- 2026-11-18: Wykrywanie oszustw (`risk_flags`): typowe zwroty (przedpłata, klucze kurierem, właściciel za granicą), cena za m² poniżej 60% mediany w okolicy, to samo zdjęcie (dHash, GD) w ofercie z innego miasta, innego typu albo z ceną różną o ponad 30%. Ta sama oferta z innego portalu to duplikat, nie ryzyko. Sygnały idą do oceny AI, na Discorda i do panelu.
- 2026-11-18: `listings:enrich-missing` co 10 minut uzupełnia porcjami starsze oferty (współrzędne, dojazdy, zdjęcia, ryzyko).
- 2026-11-20: 2FA w panelu (prośba właściciela): wbudowane MFA Filamenta z aplikacją TOTP i kodami zapasowymi, wymagane dla każdego konta. Sekret i kody zaszyfrowane `APP_KEY`. Reset przez `user:reset-2fa {email}`. Lista IP w Caddy zostaje jako druga warstwa.
- 2026-11-20: `scripts/deploy.sh` z laptopa zamiast `git pull` na serwerze (serwer nie ma repozytorium git, kod idzie rsync). Migracje w nowym obrazie przed restartem, potem `up -d --wait`, `queue:restart` i sprawdzenie `/up` przez Caddy. Wpis w `~/deploys.log`.
- 2026-11-20: Alerty systemowe tylko w panelu (prośba właściciela), z przyciskiem do logów. Discord jako opcja (`notify_alerts_discord`, domyślnie wyłączone). Każdy alert trafia też do logu jako warning.
- 2026-11-20: Logi w panelu: wolumen `logs` wspólny dla app, workerów i schedulera (wcześniej każdy kontener miał własny plik). Kanał `daily` (14 dni). Strona czyta tylko końcówkę pliku (3 MB) i tylko pliki z `storage/logs`.
- 2026-11-20: Tygodniowy raport rynku (prośba właściciela): soboty 20:00, osobno wynajem i sprzedaż, z ofert widzianych w ostatnich 7 dniach (bez duplikatów). Zapis w `market_reports`, zmiany liczone względem poprzedniego zapisanego raportu. Discord (kanał wynajmu albo sprzedaży), dzwoneczek, strona Raport rynku z podglądem na żywo, `GET /api/market-reports`. Włącznik w ustawieniach i w `/api/notification-settings`.

## Otwarte pytania

- Czy warunki OpenCode Go pozwalają na użycie z własnej aplikacji? Dokumentacja: Go jest „dla OpenCode i innych agentów do kodowania”, ruch jest monitorowany. Ocena ogłoszeń to nie jest ruch agenta do kodowania. Alternatywa zgodna z warunkami: pay-as-you-go (`https://opencode.ai/inference/openai/v1`, płatne z kredytów) albo bezpośrednie API DeepSeek.
- Duplikaty po cenie i metrażu mogą się mylić: dwa różne ogłoszenia OLX (2400 zł, 63 m2) wskazały tę samą ofertę Otodom (2390 zł, 63,9 m2). Można zaostrzyć regułę, np. wymagać tej samej liczby pokoi.
