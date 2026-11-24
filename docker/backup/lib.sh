#!/usr/bin/env bash
# Wspólne funkcje skryptów kopii bazy. Pliki w /backups (wolumen backups).
set -euo pipefail

BACKUP_DIR="${BACKUP_DIR:-/backups}"
STATUS_FILE="${BACKUP_DIR}/status.json"

log() { printf '%s %s\n' "$(date '+%Y-%m-%d %H:%M:%S')" "$*"; }

now_iso() { date --iso-8601=seconds; }

# Ustawia pola w status.json (zapis przez plik tymczasowy, żeby panel nie czytał połowy pliku).
# Użycie: status_set klucz wartość [klucz wartość ...]. Wartości null, true, false i liczby idą do JSON bez cudzysłowów.
status_set() {
    local filter='.' args=() i=0
    while [ $# -gt 1 ]; do
        local key="$1" value="$2"
        shift 2
        if [ "$value" = null ] || [ "$value" = true ] || [ "$value" = false ] || [[ "$value" =~ ^[0-9]+$ ]]; then
            filter="${filter} | .${key} = ${value}"
        else
            args+=(--arg "v${i}" "$value")
            filter="${filter} | .${key} = \$v${i}"
        fi
        i=$((i + 1))
    done
    mkdir -p "$BACKUP_DIR"
    [ -s "$STATUS_FILE" ] || echo '{}' > "$STATUS_FILE"
    jq "${args[@]}" "$filter" "$STATUS_FILE" > "${STATUS_FILE}.tmp"
    chmod 644 "${STATUS_FILE}.tmp"
    mv "${STATUS_FILE}.tmp" "$STATUS_FILE"
}

status_get() {
    if [ -s "$STATUS_FILE" ]; then
        jq -r ".$1 // empty" "$STATUS_FILE"
    fi
}

# Sekundy od daty ISO w status.json (duża liczba, gdy daty nie ma).
seconds_since() {
    local value
    value="$(status_get "$1")"
    if [ -z "$value" ]; then
        echo 999999999
    else
        echo $(($(date +%s) - $(date -d "$value" +%s)))
    fi
}

# Najnowszy plik kopii (daily albo manual).
latest_dump() {
    find "$BACKUP_DIR/daily" "$BACKUP_DIR/manual" -name '*.dump' -type f -printf '%T@ %p\n' 2>/dev/null \
        | sort -rn | head -1 | cut -d' ' -f2-
}

# Ścieżka podana względem /backups albo pełna.
resolve_dump() {
    local file="$1"
    [ "${file#/}" = "$file" ] && file="${BACKUP_DIR}/${file}"
    if [ ! -f "$file" ]; then
        echo "Nie ma pliku: $file" >&2
        return 1
    fi
    printf '%s' "$file"
}

# Usuwa najstarsze pliki ponad limit w katalogu.
rotate() {
    local dir="$1" keep="$2"
    find "$dir" -name '*.dump' -type f -printf '%T@ %p\n' | sort -rn | tail -n +"$((keep + 1))" | cut -d' ' -f2- | xargs -r rm -f
}

# Kopia poza serwer (Cloudflare R2 przez rclone), tylko gdy są wszystkie zmienne.
remote_enabled() {
    [ -n "${BACKUP_R2_BUCKET:-}" ] && [ -n "${BACKUP_R2_ACCESS_KEY_ID:-}" ] \
        && [ -n "${BACKUP_R2_SECRET_ACCESS_KEY:-}" ] && [ -n "${BACKUP_R2_ENDPOINT:-}" ]
}

remote_path() {
    printf 'r2:%s/%s' "$BACKUP_R2_BUCKET" "${BACKUP_R2_PATH:-project-keys}"
}

export_rclone_config() {
    export RCLONE_CONFIG_R2_TYPE=s3
    export RCLONE_CONFIG_R2_PROVIDER=Cloudflare
    export RCLONE_CONFIG_R2_ACCESS_KEY_ID="$BACKUP_R2_ACCESS_KEY_ID"
    export RCLONE_CONFIG_R2_SECRET_ACCESS_KEY="$BACKUP_R2_SECRET_ACCESS_KEY"
    export RCLONE_CONFIG_R2_ENDPOINT="$BACKUP_R2_ENDPOINT"
    # Token R2 zwykle nie ma prawa do tworzenia bucketu.
    export RCLONE_CONFIG_R2_NO_CHECK_BUCKET=true
}
