#!/usr/bin/env bash
set -euo pipefail

PORT="${1:-${PORT:-8080}}"
HOST="${HOST:-127.0.0.1}"
ROOT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
DB_CONFIG="$ROOT_DIR/database/bookkam-mariadb.cnf"
MYSQL_BIN="/Applications/XAMPP/xamppfiles/bin/mysql"
MYSQLD="/Applications/XAMPP/xamppfiles/sbin/mysqld"
PHP_BIN="${PHP_BIN:-/Applications/XAMPP/xamppfiles/bin/php}"
SOCKET="$ROOT_DIR/runtime/run/bookkam-mysql.sock"

mkdir -p "$ROOT_DIR/runtime/run" "$ROOT_DIR/runtime/logs" "$ROOT_DIR/runtime/tmp"

"$ROOT_DIR/database/setup-local-db.sh" >/dev/null

DB_PID=""
if ! "$MYSQL_BIN" --defaults-file="$DB_CONFIG" -u root --protocol=socket --socket="$SOCKET" -e "SELECT 1" >/dev/null 2>&1; then
  "$MYSQLD" --defaults-file="$DB_CONFIG" &
  DB_PID="$!"
  for _ in $(seq 1 30); do
    if "$MYSQL_BIN" --defaults-file="$DB_CONFIG" -u root --protocol=socket --socket="$SOCKET" -e "SELECT 1" >/dev/null 2>&1; then
      break
    fi
    sleep 1
  done
fi

cleanup() {
  if [ -n "$DB_PID" ]; then
    "$MYSQL_BIN" --defaults-file="$DB_CONFIG" -u root --protocol=socket --socket="$SOCKET" -e "SHUTDOWN" >/dev/null 2>&1 || true
  fi
}
trap cleanup EXIT INT TERM

echo "Database: bookkam on 127.0.0.1:3307"
echo "Server: http://$HOST:$PORT/"
exec "$PHP_BIN" -S "$HOST:$PORT" -t "$ROOT_DIR"
