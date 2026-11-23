#!/usr/bin/env bash
# Punkty infrastruktury (sklepy, żłobki, przedszkola, szkoły, przychodnie) z lokalnej mapy OpenStreetMap,
# tej samej co dla OSRM (scripts/osrm-prepare.sh), bez zewnętrznych serwisów. Gdy Overpass API nie działa.
# Użycie na serwerze: scripts/pois-extract.sh
set -euo pipefail
cd "$(dirname "$0")/.."

IMAGE=project-keys-osmium
VOLUME=project-keys_osrm_data

if ! docker image inspect "$IMAGE" >/dev/null 2>&1; then
    echo "Buduję obraz z osmium..."
    printf 'FROM debian:13-slim\nRUN apt-get update && apt-get install -y --no-install-recommends osmium-tool && rm -rf /var/lib/apt/lists/*\n' \
        | docker build -q -t "$IMAGE" - >/dev/null
fi

echo "Wyciągam punkty z mapy i importuję..."
docker run --rm -v "$VOLUME":/data:ro "$IMAGE" sh -c '
    set -e
    osmium tags-filter /data/region.osm.pbf \
        nwr/shop=supermarket,hypermarket \
        nwr/amenity=kindergarten,childcare,school,clinic,doctors \
        nwr/healthcare=clinic,doctor,centre \
        -o /tmp/pois.osm.pbf --overwrite >/dev/null
    osmium export /tmp/pois.osm.pbf -f geojsonseq -a type,id --geometry-types=point,linestring,polygon -o - 2>/dev/null
' | docker compose exec -T app php artisan pois:import --file=-
