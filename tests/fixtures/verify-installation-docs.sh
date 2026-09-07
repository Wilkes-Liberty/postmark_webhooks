#!/usr/bin/env bash
# Follow the README / docs/installation-upgrade.md commands on disposable sites.
set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "$0")" && pwd)"
BASE="${POSTMARK_DOCS_ROOT:-/tmp/postmark-docs}"
DRUPAL_CONSTRAINT="${DRUPAL_CONSTRAINT:-11.4.*}"

require_disposable_root() {
  local path="$1"
  local resolved
  resolved="$(python3 -c 'import os,sys; print(os.path.realpath(sys.argv[1]))' "$path")"
  case "$resolved" in
    /tmp/postmark-*|/private/tmp/postmark-*)
      ;;
    *)
      echo "Refusing unsafe path $resolved" >&2
      exit 1
      ;;
  esac
}

remove_disposable_root() {
  local path="$1"
  require_disposable_root "$path"
  if [[ -e "$path" ]]; then
    chmod -R u+w "$path" 2>/dev/null || true
    rm -rf "$path"
  fi
}

prepare_drupal() {
  local root="$1"
  remove_disposable_root "$root"
  mkdir -p "$root"
  export POSTMARK_CI_DIR="$root"
  export DRUPAL_CONSTRAINT
  export MAILER_CONSTRAINT="${MAILER_CONSTRAINT:-}"
  export AUDIT_BLOCK_INSECURE="${AUDIT_BLOCK_INSECURE:-true}"
  python3 "$SCRIPT_DIR/create-ci-fixture.py"
  composer install --working-dir="$root" --no-interaction --no-progress
}

require_dist() {
  composer require --working-dir="$1" --prefer-dist --no-interaction --no-progress "$2"
}

drush() {
  local root="$1"
  shift
  "$root/vendor/bin/drush" --root="$root/web" --yes "$@"
}

install_site() {
  local root="$1"
  local secret="$2"
  local db="$root/web/sites/default/files/.ht.sqlite"
  mkdir -p "$root/web/sites/default/files"
  drush "$root" site:install minimal \
    --db-url="sqlite://localhost/$db" \
    --account-pass=fixture-only \
    --site-name=PostmarkDocs
  chmod u+w "$root/web/sites/default/settings.php"
  # Disposable fixture only. Never copy a host POSTMARK_* token into /tmp.
  python3 - "$root/web/sites/default/settings.php" "$secret" <<'PY'
from pathlib import Path
import sys
path = Path(sys.argv[1])
secret = sys.argv[2]
if any(c not in '0123456789abcdef' for c in secret) or len(secret) < 32:
    raise SystemExit('Walkthrough secret must be a hex token.')
path.write_text(path.read_text(encoding='utf-8') + "\n$settings['postmark_webhooks.webhook_secret'] = '%s';\n" % secret, encoding='utf-8')
PY
  chmod u-w "$root/web/sites/default/settings.php"
}

assert_diagnostics() {
  local root="$1"
  local secret="$2"
  local json_file="$root/diagnostics.json"
  drush "$root" postmark-webhooks:diagnostics --format=json > "$json_file"
  python3 - "$json_file" "$secret" <<'PY'
import json, sys
raw = open(sys.argv[1], encoding='utf-8').read()
secret = sys.argv[2]
data = json.loads(raw)
assert data.get("secret_configured") is True
assert secret not in raw
print("diagnostics omit the secret and report it configured")
PY
}

WALKTHROUGH_SECRET="$(python3 -c 'import secrets; print(secrets.token_hex(32))')"

echo "=== Shared Drupal fixture ==="
require_disposable_root "$BASE"
if [[ -d "$BASE/vendor" && -f "$BASE/composer.json" ]]; then
  echo "Reusing $BASE"
else
  prepare_drupal "$BASE"
fi

echo "=== Fresh install (README commands) ==="
INSTALL="$BASE-install"
remove_disposable_root "$INSTALL"
cp -a "$BASE" "$INSTALL"
require_dist "$INSTALL" "drupal/postmark_webhooks:^1.0"
install_site "$INSTALL" "$WALKTHROUGH_SECRET"
drush "$INSTALL" pm:enable postmark_webhooks
drush "$INSTALL" cache:rebuild
drush "$INSTALL" pm:list --filter=postmark_webhooks --status=enabled
drush "$INSTALL" config:get postmark_webhooks.settings
assert_diagnostics "$INSTALL" "$WALKTHROUGH_SECRET"
echo "Fresh install walkthrough passed."

echo "=== Alpha1 to current package upgrade ==="
UPGRADE="$BASE-upgrade"
remove_disposable_root "$UPGRADE"
cp -a "$BASE" "$UPGRADE"
require_dist "$UPGRADE" "drupal/postmark_webhooks:1.0.0-alpha1"
install_site "$UPGRADE" "$WALKTHROUGH_SECRET"
drush "$UPGRADE" pm:enable postmark_webhooks
require_dist "$UPGRADE" "drupal/postmark_webhooks:^1.0"
drush "$UPGRADE" updatedb
drush "$UPGRADE" cache:rebuild
assert_diagnostics "$UPGRADE" "$WALKTHROUGH_SECRET"
schema="$(drush "$UPGRADE" php:eval 'echo \Drupal::keyValue("system.schema")->get("postmark_webhooks");')"
python3 -c 'import sys; schema=int(sys.argv[1] or 0); assert schema >= 10006, schema; print("schema version %s after updatedb" % schema)' "$schema"
echo "Alpha1 upgrade walkthrough passed."
