#!/usr/bin/env bash
# Pobiera mapę regionu z Geofabrik i liczy dane tras OSRM dla auta i roweru (wolumen osrm_data).
# Uruchom raz przed pierwszym startem, potem np. raz w miesiącu, żeby odświeżyć drogi.
# Użycie: scripts/osrm-prepare.sh   (region: OSRM_REGION=europe/poland/podkarpackie)
set -euo pipefail
cd "$(dirname "$0")/.."

IMAGE=ghcr.io/project-osrm/osrm-backend:v6.0.0
REGION=${OSRM_REGION:-europe/poland/podkarpackie}
VOLUME=project-keys_osrm_data

docker volume create "$VOLUME" >/dev/null

echo "Pobieram ${REGION}..."
docker run --rm -v "$VOLUME":/data alpine:3 \
    wget -q -O /data/region.osm.pbf "https://download.geofabrik.de/${REGION}-latest.osm.pbf"

for profile in car bicycle; do
    echo "Liczę trasy: ${profile}..."
    docker run --rm -v "$VOLUME":/data "$IMAGE" sh -c "
        set -e
        rm -rf /data/${profile}.new && mkdir -p /data/${profile}.new
        cp /data/region.osm.pbf /data/${profile}.new/region.osm.pbf
        osrm-extract -p /opt/${profile}.lua /data/${profile}.new/region.osm.pbf
        osrm-partition /data/${profile}.new/region.osrm
        osrm-customize /data/${profile}.new/region.osrm
        rm /data/${profile}.new/region.osm.pbf
        rm -rf /data/${profile} && mv /data/${profile}.new /data/${profile}
    " > /dev/null
done

docker compose restart osrm-car osrm-bike 2>/dev/null || true
echo "Gotowe."
