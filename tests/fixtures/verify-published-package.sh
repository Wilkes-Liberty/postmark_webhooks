#!/usr/bin/env bash
# Install the Drupal.org archive (not a git checkout) and run its suite.
set -euo pipefail

VERSION="${POSTMARK_PUBLISHED_VERSION:-1.0.0-alpha2}"
EXPECTED_SHA1="${POSTMARK_PUBLISHED_SHA1:-e31e9f67b554cdaf35d04549d955310c0d1d3c30}"
ROOT="${POSTMARK_PUBLISHED_ROOT:-/tmp/postmark-published}"
DRUPAL_CONSTRAINT="${DRUPAL_CONSTRAINT:-11.4.*}"
SIMPLETEST_DB="${SIMPLETEST_DB:?SIMPLETEST_DB is required}"
SCRIPT_DIR="$(cd "$(dirname "$0")" && pwd)"

require_dist() {
  composer require --working-dir="$1" --prefer-dist --no-interaction --no-progress "$2"
}

assert_published_dist() {
  local module="$1"
  if [[ ! -d "$module" ]]; then
    echo "Published module directory missing: $module" >&2
    exit 1
  fi
  if [[ -L "$module" || -e "$module/.git" ]]; then
    echo "Refusing a git checkout at $module; published verification needs the Composer dist." >&2
    exit 1
  fi
}

require_disposable_root() {
  local path="$1"
  local resolved
  resolved="$(python3 -c 'import os,sys; print(os.path.realpath(sys.argv[1]))' "$path")"
  case "$resolved" in
    /tmp/postmark-*|/private/tmp/postmark-*)
      ;;
    *)
      echo "Refusing to delete unsafe path $resolved" >&2
      exit 1
      ;;
  esac
}

require_disposable_root "$ROOT"
rm -rf "$ROOT"
mkdir -p "$ROOT"
export POSTMARK_CI_DIR="$ROOT"
export DRUPAL_CONSTRAINT
export MAILER_CONSTRAINT="${MAILER_CONSTRAINT:-}"
export AUDIT_BLOCK_INSECURE="${AUDIT_BLOCK_INSECURE:-true}"
python3 "$SCRIPT_DIR/create-ci-fixture.py"
composer install --working-dir="$ROOT" --no-interaction --no-progress

require_dist "$ROOT" "drupal/postmark_webhooks:$VERSION"
MODULE="$ROOT/web/modules/contrib/postmark_webhooks"
assert_published_dist "$MODULE"

LOCK_SHA1="$(python3 - <<PY
import json
lock = json.load(open("$ROOT/composer.lock"))
for pkg in lock.get("packages", []):
    if pkg.get("name") == "drupal/postmark_webhooks":
        print(pkg.get("dist", {}).get("shasum", ""))
        break
PY
)"
if [[ "$LOCK_SHA1" != "$EXPECTED_SHA1" ]]; then
  echo "Published shasum $LOCK_SHA1 does not match $EXPECTED_SHA1" >&2
  exit 1
fi

composer show --working-dir="$ROOT" --no-ansi drupal/postmark_webhooks
php -r 'echo "PHP ", PHP_VERSION, "\n";'

mkdir -p "$ROOT/web/sites/simpletest/browser_output"
(
  cd "$ROOT/web"
  PHP_CLI_SERVER_WORKERS=4 php -S 127.0.0.1:8888 -t . .ht.router.php > /tmp/postmark-published-http.log 2>&1 &
  echo $! > /tmp/postmark-published-http.pid
)
server_pid="$(cat /tmp/postmark-published-http.pid)"
trap 'pkill -TERM -P "$server_pid" 2>/dev/null || true; kill "$server_pid" 2>/dev/null || true; wait "$server_pid" 2>/dev/null || true' EXIT
ready=false
for attempt in $(seq 1 20); do
  if ! kill -0 "$server_pid" 2>/dev/null; then
    cat /tmp/postmark-published-http.log >&2
    echo "Published-package HTTP server exited before it was ready." >&2
    exit 1
  fi
  if curl --fail --silent --max-time 5 --output /dev/null http://127.0.0.1:8888/robots.txt; then
    ready=true
    break
  fi
  sleep 1
done
if [[ "$ready" != true ]]; then
  cat /tmp/postmark-published-http.log >&2
  echo "Published-package HTTP server did not become ready." >&2
  exit 1
fi

(
  cd "$ROOT"
  SIMPLETEST_BASE_URL=http://127.0.0.1:8888 SIMPLETEST_DB="$SIMPLETEST_DB" \
    vendor/bin/phpunit -c web/core web/modules/contrib/postmark_webhooks/tests
)
if [[ -d "$MODULE/modules/postmark_webhooks_reconcile/tests" ]]; then
  (
    cd "$ROOT"
    SIMPLETEST_BASE_URL=http://127.0.0.1:8888 SIMPLETEST_DB="$SIMPLETEST_DB" \
      vendor/bin/phpunit -c web/core "$MODULE/modules/postmark_webhooks_reconcile/tests"
  )
fi
python3 "$SCRIPT_DIR/drush-diagnostics.py" "$ROOT"
"$ROOT/vendor/bin/drush" --root="$ROOT/web" cache:rebuild -y

echo "Published package $VERSION sha1 $LOCK_SHA1 verified."

# Composer-replace published alpha1 files with alpha2, then run alpha2 tests
# (including interrupted-batch upgrade coverage that ships in the archive).
UPGRADE_ROOT="${POSTMARK_PUBLISHED_UPGRADE_ROOT:-/tmp/postmark-published-upgrade}"
require_disposable_root "$UPGRADE_ROOT"
rm -rf "$UPGRADE_ROOT"
mkdir -p "$UPGRADE_ROOT"
export POSTMARK_CI_DIR="$UPGRADE_ROOT"
python3 "$SCRIPT_DIR/create-ci-fixture.py"
composer install --working-dir="$UPGRADE_ROOT" --no-interaction --no-progress
require_dist "$UPGRADE_ROOT" "drupal/postmark_webhooks:1.0.0-alpha1"
require_dist "$UPGRADE_ROOT" "drupal/postmark_webhooks:$VERSION"
UPGRADE_MODULE="$UPGRADE_ROOT/web/modules/contrib/postmark_webhooks"
assert_published_dist "$UPGRADE_MODULE"
UPGRADE_META="$(python3 - <<PY
import json
lock = json.load(open("$UPGRADE_ROOT/composer.lock"))
for pkg in lock.get("packages", []):
    if pkg.get("name") == "drupal/postmark_webhooks":
        print(pkg.get("dist", {}).get("shasum", "") + " " + pkg.get("version", ""))
        break
PY
)"
printf 'Upgrade fixture resolved: %s\n' "$UPGRADE_META"
mkdir -p "$UPGRADE_ROOT/web/sites/simpletest/browser_output"
(
  cd "$UPGRADE_ROOT"
  SIMPLETEST_BASE_URL=http://127.0.0.1:8888 SIMPLETEST_DB="$SIMPLETEST_DB" \
    vendor/bin/phpunit -c web/core \
    web/modules/contrib/postmark_webhooks/tests/src/Kernel/PostmarkSuppressionTest.php \
    web/modules/contrib/postmark_webhooks/tests/src/Kernel/PostmarkWebhookAuthTest.php
)
echo "Published alpha1-to-$VERSION Composer upgrade verified."
