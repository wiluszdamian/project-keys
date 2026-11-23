#!/usr/bin/env bash
# Uruchamia testy na serwerze w kontenerze z PHP 8.5, w sieci compose (baza mieszkania_test).
# Użycie: scripts/test.sh [argumenty dla artisan test], np. scripts/test.sh --filter=Olx
set -euo pipefail
cd "$(dirname "$0")/.."

# Kod z katalogu, ale pakiety (vendor) i lista wykrytych pakietów (bootstrap/cache) z obrazu:
# pusty wolumen startuje z zawartością obrazu. Bez tego testy brały stary vendor i stary
# packages.php z katalogu na serwerze (rsync pomija vendor), więc nie widziały nowych pakietów.
docker run --rm \
    --network project-keys_default \
    -v "$PWD":/var/www/html \
    -v /var/www/html/vendor \
    -v /var/www/html/bootstrap/cache \
    -u "$(id -u):$(id -g)" \
    project-keys-app php artisan test "$@"
