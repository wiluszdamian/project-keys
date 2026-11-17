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
| 3 Otodom | do zrobienia | | |
| 4 OLX | do zrobienia | | |
| 5 Harmonogram | do zrobienia | | |
| 6 AI | do zrobienia | | |
| 7 Powiadomienia | do zrobienia | | |
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

## Otwarte pytania

- Czy warunki OpenCode Go pozwalają na użycie z własnej aplikacji?
- Dokładne ID modelu DeepSeek w OpenCode Go.
- Domena dla HTTPS.
