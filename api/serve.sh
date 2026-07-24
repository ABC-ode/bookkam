#!/usr/bin/env bash
set -euo pipefail

PORT="${1:-${PORT:-8080}}"
HOST="${HOST:-127.0.0.1}"
DOCROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"

PHP_BIN="${PHP_BIN:-}"
if [ -z "$PHP_BIN" ]; then
  for candidate in \
    php \
    /Applications/XAMPP/xamppfiles/bin/php \
    /Applications/MAMP/bin/php/php8.4.0/bin/php \
    /Applications/MAMP/bin/php/php8.3.0/bin/php \
    /Applications/MAMP/bin/php/php8.2.0/bin/php \
    /Applications/MAMP/bin/php/php8.1.0/bin/php
  do
    if command -v "$candidate" >/dev/null 2>&1; then
      PHP_BIN="$(command -v "$candidate")"
      break
    elif [ -x "$candidate" ]; then
      PHP_BIN="$candidate"
      break
    fi
  done
fi

if [ -z "$PHP_BIN" ]; then
  echo "PHP was not found."
  echo "Install PHP or set PHP_BIN, for example:"
  echo "  PHP_BIN=/Applications/XAMPP/xamppfiles/bin/php ./serve.sh 8080"
  exit 1
fi

echo "Serving BOOKKAM from $DOCROOT"
echo "URL: http://$HOST:$PORT/"
echo "PHP: $PHP_BIN"
exec "$PHP_BIN" -S "$HOST:$PORT" -t "$DOCROOT"
