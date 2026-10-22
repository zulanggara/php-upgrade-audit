#!/usr/bin/env bash
# Install the analysis tools (Rector, PHP_CodeSniffer + PHPCompatibility, composer/semver) into tools/.
# Nothing is installed into the projects you audit.
set -euo pipefail

DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"

if ! command -v php >/dev/null 2>&1; then
  echo "php not found. Install PHP 8.1+ (ideally the version you are upgrading to)." >&2
  exit 1
fi
if ! php -r 'exit(PHP_VERSION_ID >= 80100 ? 0 : 1);'; then
  echo "PHP $(php -r 'echo PHP_VERSION;') is too old to run the scripts; PHP 8.1+ is required." >&2
  exit 1
fi
if ! command -v composer >/dev/null 2>&1; then
  echo "composer not found. See https://getcomposer.org/download/" >&2
  exit 1
fi

composer install --no-interaction --working-dir="$DIR/tools"

echo
echo "PHP used for the audit: $(php -r 'echo PHP_VERSION;')"
echo "Make sure this is the PHP version you are upgrading TO (e.g. 8.5.12); lint and class loading use it."
echo "Ready. Try: php $DIR/scripts/audit.php $DIR/fixtures/legacy-app --out=/tmp/php-audit-demo --name=legacy-app"
