#!/usr/bin/env bash
# Wdrożenie jedną komendą z laptopa: kod na serwer, obrazy, migracje, restart, sprawdzenie.
# Użycie: scripts/deploy.sh [--tests] [--dirty] [--host project-keys]
#   --tests  uruchom testy na serwerze przed restartem (przerywa przy błędzie)
#   --dirty  pozwól wdrożyć niezacommitowane zmiany
#   --host   alias SSH serwera (domyślnie project-keys)
set -euo pipefail
cd "$(dirname "$0")/.."

HOST=project-keys
TESTS=0
DIRTY=0

while [ $# -gt 0 ]; do
    case "$1" in
        --tests) TESTS=1 ;;
        --dirty) DIRTY=1 ;;
        --host) HOST="$2"; shift ;;
        -h|--help) sed -n '2,6p' "$0"; exit 0 ;;
        *) echo "Nieznana opcja: $1" >&2; exit 1 ;;
    esac
    shift
done

step() { printf '\n\033[1m==> %s\033[0m\n' "$1"; }

if [ -n "$(git status --porcelain)" ] && [ "$DIRTY" = 0 ]; then
    echo "Są niezacommitowane zmiany. Zrób commit albo dodaj --dirty." >&2
    git status --short >&2
    exit 1
fi

REV="$(git rev-parse --short HEAD) ($(git rev-parse --abbrev-ref HEAD))"
step "Wdrażam ${REV} na ${HOST}"

step "Kopiuję kod"
# Bez .env, vendor i logów: na serwerze są własne. CSS i JS Filamenta są w obrazie.
rsync -a --delete \
    --exclude .git --exclude .env --exclude vendor --exclude node_modules \
    --exclude 'bootstrap/cache/*.php' --exclude 'storage/logs/*' \
    --exclude 'storage/framework/*/*' \
    --exclude 'public/js/filament' --exclude 'public/css/filament' --exclude 'public/fonts/filament' \
    ./ "${HOST}:project-keys/"

ssh "$HOST" TESTS="$TESTS" REV="'$REV'" bash -s <<'REMOTE'
set -euo pipefail
cd ~/project-keys
# Skrypt przychodzi przez stdin, więc polecenia docker dostają </dev/null, żeby go nie zjadły.
step() { printf '\n\033[1m==> %s\033[0m\n' "$1"; }

step "Buduję obrazy"
docker compose build -q </dev/null

if [ "$TESTS" = 1 ]; then
    step "Testy"
    scripts/test.sh </dev/null
fi

step "Migracje (nowy kod, przed restartem)"
docker compose run --rm -T app php artisan migrate --force </dev/null

step "Restart usług"
docker compose up -d --remove-orphans --wait </dev/null
docker compose exec -T app php artisan queue:restart </dev/null

step "Sprawdzam, czy aplikacja odpowiada"
DOMAIN="$(grep -E '^CADDY_DOMAIN=' .env | cut -d= -f2- | tr -d '"' || true)"
for i in 1 2 3 4 5 6; do
    if [ -n "$DOMAIN" ] && [ "${DOMAIN#http://}" = "$DOMAIN" ]; then
        CODE="$(curl -s -o /dev/null -w '%{http_code}' --resolve "${DOMAIN}:443:127.0.0.1" "https://${DOMAIN}/up" || true)"
    else
        CODE="$(curl -s -o /dev/null -w '%{http_code}' http://127.0.0.1:8080/up || true)"
    fi
    [ "$CODE" = 200 ] && break
    sleep 5
done

if [ "$CODE" != 200 ]; then
    echo "Aplikacja odpowiada kodem ${CODE}. Sprawdź: docker compose logs --tail=50 app caddy" >&2
    exit 1
fi

docker image prune -f >/dev/null
docker compose ps --format '{{.Service}}: {{.Status}}' | sort | uniq -c
echo "$REV $(date -Iseconds)" >> ~/deploys.log
printf '\n\033[1;32mGotowe: %s\033[0m\n' "$REV"
REMOTE
