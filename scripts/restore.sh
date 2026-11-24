#!/usr/bin/env bash
# Kopie bazy na serwerze (uruchamiaj w ~/project-keys). Kopie robi kontener backup.
# Użycie:
#   scripts/restore.sh --list               lista kopii i stan (status.json)
#   scripts/restore.sh --test [plik]        test przywracania do tymczasowej bazy, produkcja bez zmian
#   scripts/restore.sh <plik>               przywraca kopię do bazy produkcyjnej (np. daily/pk-20261124-030000.dump)
#   scripts/restore.sh --from <plik.dump>   przywraca kopię z dysku serwera (np. skopiowaną z laptopa)
# Przed przywróceniem robi kopię bezpieczeństwa (manual/...-przed-przywroceniem.dump).
set -euo pipefail
cd "$(dirname "$0")/.."

step() { printf '\n\033[1m==> %s\033[0m\n' "$1"; }
backup() { docker compose exec -T backup "$@"; }

usage() {
    sed -n '2,8p' "$0"
    exit "${1:-0}"
}

[ $# -gt 0 ] || usage 1

case "$1" in
    -h|--help) usage ;;
    --list) backup backup-list; exit 0 ;;
    --test) backup backup-verify ${2:+"$2"}; exit 0 ;;
    --from)
        [ -n "${2:-}" ] || usage 1
        [ -f "$2" ] || { echo "Nie ma pliku: $2" >&2; exit 1; }
        step "Wgrywam $2 do kontenera backup"
        FILE="$(backup backup-receive < "$2")"
        echo "Zapisana jako ${FILE}"
        ;;
    -*) echo "Nieznana opcja: $1" >&2; usage 1 ;;
    *) FILE="$1" ;;
esac

step "Sprawdzam kopię ${FILE} (test przywracania do tymczasowej bazy)"
backup backup-verify "$FILE"

echo
echo "Baza produkcyjna zostanie zastąpiona kopią ${FILE}."
echo "Wszystko, co zmieniło się po tej kopii (nowe oferty, statusy, notatki), zniknie."
read -r -p "Wpisz TAK, żeby kontynuować: " ANSWER </dev/tty
[ "$ANSWER" = TAK ] || { echo "Przerwane."; exit 1; }

step "Kopia bezpieczeństwa obecnej bazy"
backup backup-now przed-przywroceniem

step "Zatrzymuję aplikację"
STOPPED=0
start_again() {
    if [ "$STOPPED" = 1 ]; then
        step "Uruchamiam aplikację"
        docker compose up -d --wait app worker worker-ai scheduler </dev/null
    fi
}
trap start_again EXIT
docker compose stop app worker worker-ai scheduler
STOPPED=1

step "Przywracam ${FILE}"
backup backup-restore "$FILE"

step "Migracje (kopia mogła być sprzed nowych migracji)"
docker compose run --rm -T app php artisan migrate --force </dev/null

start_again
STOPPED=0
docker compose exec -T app php artisan queue:restart </dev/null

printf '\n\033[1;32mPrzywrócone: %s\033[0m\n' "$FILE"
echo "Kopia sprzed przywrócenia jest w manual/ (scripts/restore.sh --list)."
