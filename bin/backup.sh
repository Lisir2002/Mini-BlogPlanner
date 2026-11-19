#!/usr/bin/env bash
set -euo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
REASON="manual"
JSON=0

for arg in "$@"; do
  case "$arg" in
    --reason=*) REASON="${arg#*=}" ;;
    --json) JSON=1 ;;
    --check) CHECK=1 ;;
    *) echo "Unknown argument: $arg" >&2; exit 1 ;;
  esac
done

ENV_FILE=""
for candidate in "$ROOT/.env" "$(dirname "$ROOT")/.env" "$(dirname "$(dirname "$ROOT")")/.env"; do
  if [[ -f "$candidate" ]]; then
    ENV_FILE="$candidate"
    break
  fi
done
if [[ -n "$ENV_FILE" ]]; then
  set -a
  # shellcheck disable=SC1091
  source "$ENV_FILE"
  set +a
fi

STORAGE_ROOT="${STORAGE_DIR:-$ROOT/storage}"
BACKUP_DIR="${BACKUP_DIR:-$STORAGE_ROOT/backups}"
MYSQL_DUMP_BIN="${MYSQL_DUMP_BIN:-mysqldump}"
MYSQL_BIN="${MYSQL_BIN:-mysql}"
DB_HOST="${DB_HOST:-127.0.0.1}"
DB_PORT="${DB_PORT:-3306}"
DB_NAME="${DB_NAME:?DB_NAME is required}"
DB_USER="${DB_USER:?DB_USER is required}"

if [[ "${CHECK:-0}" == "1" ]]; then
  latest="$(find "$BACKUP_DIR" -maxdepth 2 -name manifest.json -printf '%T@ %p\n' 2>/dev/null | sort -nr | head -n1 | cut -d' ' -f2-)"
  if [[ -z "$latest" ]]; then
    echo '{"ok":false,"code":4,"message":"No backup manifest found."}' >&2
    exit 4
  fi
  dir="$(dirname "$latest")"
  (cd "$dir" && sha256sum -c checksums.txt >/dev/null)
  if [[ "$JSON" == "1" ]]; then
    echo "{\"ok\":true,\"backup_dir\":\"$dir\"}"
  else
    echo "Backup OK: $dir"
  fi
  exit 0
fi

timestamp="$(date -u +%Y%m%dT%H%M%SZ)"
backup_id="bkp_${timestamp}_$(printf '%s' "$RANDOM$RANDOM" | sha256sum | cut -c1-6)"
target="$BACKUP_DIR/$backup_id"
mkdir -p "$target"
chmod 0700 "$target"

export MYSQL_PWD="${DB_PASSWORD:-}"
"$MYSQL_DUMP_BIN" \
  --host="$DB_HOST" --port="$DB_PORT" --user="$DB_USER" \
  --single-transaction --routines --triggers --events --set-gtid-purged=OFF --no-tablespaces --skip-masking-policies --skip-add-drop-masking-policy \
  "$DB_NAME" > "$target/db.sql"

if [[ -d "$STORAGE_ROOT/uploads" ]]; then
  tar -czf "$target/uploads.tar.gz" -C "$STORAGE_ROOT" uploads
fi

cp "$ROOT/version.json" "$target/version.json"

"$MYSQL_BIN" --host="$DB_HOST" --port="$DB_PORT" --user="$DB_USER" -N -B \
  -e "SELECT schema_version, app_version, applied_at FROM schema_meta WHERE id = 1" \
  "$DB_NAME" > "$target/schema_meta.tsv" 2>/dev/null || true

(
  cd "$target"
  sha256sum db.sql version.json schema_meta.tsv > checksums.txt
  if [[ -f uploads.tar.gz ]]; then
    sha256sum uploads.tar.gz >> checksums.txt
  fi
)

"${PHP_BIN:-php}" -r '
$target = $argv[1];
$reason = $argv[2];
$manifest = [
  "backup_id" => basename($target),
  "reason" => $reason,
  "created_at" => gmdate("c"),
  "db" => "db.sql",
  "version" => "version.json",
  "schema_meta" => "schema_meta.tsv",
  "uploads" => is_file($target . "/uploads.tar.gz") ? "uploads.tar.gz" : null,
  "checksums" => "checksums.txt",
];
file_put_contents($target . "/manifest.json", json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL);
' "$target" "$REASON"

if [[ "$JSON" == "1" ]]; then
  "${PHP_BIN:-php}" -r '
$target = $argv[1];
$manifest = json_decode(file_get_contents($target . "/manifest.json"), true);
echo json_encode(["ok" => true, "data" => $manifest], JSON_UNESCAPED_SLASHES) . PHP_EOL;
' "$target"
else
  echo "Backup created: $target"
fi
