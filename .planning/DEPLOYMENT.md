# Wdrożenie na VPS Contabo

Serwer: Ubuntu 24.04, x86_64. Dokładne parametry (CPU, RAM, dysk) wpisz do STATUS.md w fazie 0.

## 1. Podstawowe zabezpieczenie (faza 0)

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

## 2. Docker

- Instalacja z oficjalnego repozytorium Dockera (nie z pakietu Ubuntu `docker.io`).
- Użytkownik `deploy` w grupie `docker`.
- `/etc/docker/daemon.json` z rotacją logów: `{"log-driver":"json-file","log-opts":{"max-size":"10m","max-file":"3"}}`.
- Pamiętaj: porty opublikowane przez Dockera omijają ufw. Publikuje tylko `caddy` (80, 443).

## 3. Aplikacja

- Katalog: `/home/deploy/mieszkania`.
- `.env` na podstawie `.planning/env.example`, uprawnienia 600.
- `docker compose up -d --build`, potem `php artisan migrate --force` w kontenerze `app`.
- Caddy: domena z `CADDY_DOMAIN`, automatyczny certyfikat.
- Bez domeny: Caddy tylko na `127.0.0.1:8080`, dostęp przez tunel `ssh -L 8080:localhost:8080 deploy@IP`.

## 4. Aktualizacje kodu

Skrypt `deploy.sh`: `git pull`, `docker compose build`, `docker compose up -d`, `migrate --force`, `queue:restart`, `config:cache`, `route:cache`.

## 5. Backup

- Codziennie o 3:30 `pg_dump` (skompresowany) do `/home/deploy/backups`, trzymaj 14 ostatnich.
- Opcjonalnie kopia poza serwer (np. rclone do chmury). Zapytaj użytkownika, czy chce i gdzie.
- Skrypt `restore.sh` i jednorazowy test przywracania do osobnej bazy.

## 6. Monitoring (prosty)

- Endpoint `/api/health`.
- Alerty z NOTIFICATIONS.md (brak przebiegów, blokady, błędy AI).
- Opcjonalnie zewnętrzny darmowy monitor (np. UptimeRobot) na `/api/health`.
