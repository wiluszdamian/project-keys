# Wdrożenie na VPS Contabo

Serwer: Debian 13 (plan zakładał Ubuntu 24.04), x86_64, 155.133.197.67. Parametry w STATUS.md. Logowanie: `ssh project-keys` (użytkownik `deploy`, klucz `~/.ssh/id_ed25519_project_keys`).

## 1. Podstawowe zabezpieczenie (faza 0) ✅

Zrobione 2026-11-17. Ustawienia SSH są w `/etc/ssh/sshd_config.d/00-hardening.conf`.

Kolejność ma znaczenie, żeby nie odciąć się od serwera. Każdy krok zmieniający SSH lub firewall: najpierw pokaż komendę, poczekaj na zgodę.

1. Aktualizacja systemu: `apt update && apt upgrade -y`.
2. Użytkownik `deploy` z sudo, klucz SSH użytkownika w `~deploy/.ssh/authorized_keys`.
3. Sprawdzenie logowania jako `deploy` w nowej sesji, zanim cokolwiek wyłączysz.
4. `sshd_config`: `PasswordAuthentication no`, `PermitRootLogin no`. Restart SSH, test w nowej sesji.
5. ufw: `allow OpenSSH`, `allow 80/tcp`, `allow 443/tcp`, potem `enable`.
6. `fail2ban` dla SSH.
7. `unattended-upgrades` dla poprawek bezpieczeństwa.
8. Swap 2 GB, jeśli serwer ma mniej niż 8 GB RAM.
9. Strefa czasowa `Europe/Warsaw`.

## 2. Docker ✅

- Instalacja z oficjalnego repozytorium Dockera dla Debiana (nie z pakietu `docker.io`).
- Użytkownik `deploy` w grupie `docker`.
- `/etc/docker/daemon.json` z rotacją logów: `{"log-driver":"json-file","log-opts":{"max-size":"10m","max-file":"3"}}`.
- Pamiętaj: porty opublikowane przez Dockera omijają ufw. Publikuje tylko `caddy` (80, 443).

## 3. Aplikacja

- Katalog: `/home/deploy/project-keys`.
- `.env` na podstawie `.env.example` z katalogu głównego, uprawnienia 600. Na serwerze deweloperskim `INSTALL_DEV=1`.
- `docker compose up -d --build`, potem `php artisan migrate --force` w kontenerze `app`.
- Caddy: domena z `CADDY_DOMAIN`, automatyczny certyfikat.
- Bez domeny (stan obecny): Caddy tylko na `127.0.0.1:8080`, dostęp przez tunel `ssh -L 8080:localhost:8080 project-keys`. Z domeną: `CADDY_DOMAIN`, `CADDY_HTTP_BIND=80`, `CADDY_HTTPS_BIND=443` w `.env`.
- Testy: `scripts/test.sh` na serwerze (baza `mieszkania_test` w tym samym Postgresie, użytkownik `test`).

## 4. Aktualizacje kodu

Na razie kod trafia na serwer przez rsync z komputera dewelopera, potem `docker compose build` i `up -d`. `deploy.sh` w fazie 8.

Skrypt `deploy.sh`: `git pull`, `docker compose build`, `docker compose up -d`, `migrate --force`, `queue:restart`, `config:cache`, `route:cache`.

## 5. Backup

- Codziennie o 3:30 `pg_dump` (skompresowany) do `/home/deploy/backups`, trzymaj 14 ostatnich.
- Opcjonalnie kopia poza serwer (np. rclone do chmury). Zapytaj użytkownika, czy chce i gdzie.
- Skrypt `restore.sh` i jednorazowy test przywracania do osobnej bazy.

## 6. Monitoring (prosty)

- Endpoint `/api/health`.
- Alerty z NOTIFICATIONS.md (brak przebiegów, blokady, błędy AI).
- Opcjonalnie zewnętrzny darmowy monitor (np. UptimeRobot) na `/api/health`.
