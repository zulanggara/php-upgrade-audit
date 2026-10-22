#!/usr/bin/env bash
# Install the analysis tools (Rector, PHP_CodeSniffer + PHPCompatibility, composer/semver) into tools/,
# then check that they run. Nothing is installed into the projects you audit.
set -euo pipefail

DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"

if ! command -v php >/dev/null 2>&1; then
  echo "php not found. Install PHP 8.1+ (ideally the version you are upgrading to)." >&2
  exit 2
fi
if ! php -r 'exit(PHP_VERSION_ID >= 80100 ? 0 : 1);'; then
  echo "PHP $(php -r 'echo PHP_VERSION;') is too old to run the scripts; PHP 8.1+ is required." >&2
  exit 2
fi
if ! command -v composer >/dev/null 2>&1; then
  echo "composer not found. Install it from https://getcomposer.org/download/ and run this again." >&2
  exit 2
fi
for ext in tokenizer json mbstring; do
  php -r "exit(extension_loaded('$ext') ? 0 : 1);" || { echo "PHP extension '$ext' is missing; install it and run this again." >&2; exit 2; }
done
command -v diff >/dev/null 2>&1 || echo "Note: 'diff' not found; compare_framework.php needs it for patch diffs." >&2
command -v git >/dev/null 2>&1 || echo "Note: 'git' not found; scan profiles will not show the branch/commit." >&2

composer install --no-interaction --no-progress --working-dir="$DIR/tools"

# Check that the tools actually run (a broken install would otherwise only show up as empty report sections).
V="$DIR/tools/vendor"
ok=1
php "$V/bin/rector" --version >/dev/null 2>&1 || { echo "Rector does not run: php $V/bin/rector --version" >&2; ok=0; }
php "$V/bin/phpcs" --runtime-set installed_paths "$V/phpcompatibility/php-compatibility,$V/phpcsstandards/phpcsutils" -i 2>/dev/null \
  | grep -q PHPCompatibility || { echo "PHPCompatibility is not available to phpcs." >&2; ok=0; }
php -r "require '$V/autoload.php'; exit(class_exists('Composer\\Semver\\Semver') ? 0 : 1);" || { echo "composer/semver is missing." >&2; ok=0; }
[ "$ok" = 1 ] || { echo "Setup incomplete: fix the problem above and run bash scripts/setup.sh again." >&2; exit 2; }

PHPV="$(php -r 'echo PHP_VERSION;')"
cat <<EOF

Tools installed and working (Rector, PHPCompatibility, composer/semver).
PHP that will run the audit: $PHPV
  "php -l" and class loading prove behaviour on THIS version. Use the PHP you are upgrading TO
  (e.g. 8.5.12), or pass --php=/path/to/that/php to audit.php.

Try it on the bundled sample project:
  php $DIR/scripts/audit.php $DIR/fixtures/legacy-app --out=\$HOME/php-audit-store --name=legacy-app
  php $DIR/scripts/render.php --store=\$HOME/php-audit-store --out=\$HOME/php-audit-store/report.html --offline
  php -S 127.0.0.1:8085 -t \$HOME/php-audit-store     # then open http://127.0.0.1:8085/report.html

All options: php $DIR/scripts/audit.php --help
EOF
