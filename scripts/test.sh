#!/usr/bin/env bash
# Uruchamia testy na serwerze w kontenerze z PHP 8.5, w sieci compose (baza mieszkania_test).
# Użycie: scripts/test.sh [argumenty dla artisan test], np. scripts/test.sh --filter=Olx
set -euo pipefail
cd "$(dirname "$0")/.."

# Kod z katalogu, ale pakiety (vendor) z obrazu: pusty wolumen startuje z zawartością obrazu.
# Bez tego testy brały stary vendor z katalogu na serwerze (rsync go pomija).
docker run --rm \
    --network project-keys_default \
    -v "$PWD":/var/www/html \
    -v /var/www/html/vendor \
    -u "$(id -u):$(id -g)" \
    project-keys-app php artisan test "$@"
