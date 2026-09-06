#!/usr/bin/env python3
"""Verify real command discovery and JSON on a disposable Drupal fixture."""
import json
import os
from pathlib import Path
import subprocess
import sys

root = Path(sys.argv[1]).resolve()
command = [str(root / 'vendor/bin/drush'), '--root=' + str(root / 'web')]


def drush(*args):
    return subprocess.check_output(command + list(args), cwd=root, text=True)


if '--installed' not in sys.argv:
    # This script is only for the isolated CI fixture, never an existing site.
    if (root / 'web/sites/default/settings.php').exists():
        raise SystemExit('Refusing to install over an existing site.')
    drush('site:install', 'minimal', '--db-url=' + os.environ['SIMPLETEST_DB'],
          '--db-prefix=postmark_drush_', '--account-pass=fixture-only', '-y')
    drush('pm:enable', 'postmark_webhooks', '-y')

drush('php:eval', "\\Drupal::service('postmark_webhooks.suppression_store')->record(["
      "'event_type'=>'Bounce','bounce_type'=>'HardBounce','recipient'=>'private@example.com',"
      "'created'=>10,'event_key'=>str_repeat('a',64)]);")
drush('config:set', 'postmark_webhooks.settings', 'enabled', 'true', '--input-format=yaml', '-y')
active = json.loads(drush('postmark-webhooks:status', 'private@example.com', '--format=json'))
assert active['effective_block'] is True
assert active['policy']['reason'] == 'hard:HardBounce'
uncovered = json.loads(drush('pm-wh:status', 'private@example.com', '--mail-path=direct_symfony', '--format=json'))
assert uncovered['covered'] is False and uncovered['effective_block'] is False
drush('config:set', 'postmark_webhooks.settings', 'enabled', 'false', '--input-format=yaml', '-y')
disabled = json.loads(drush('pm-wh:status', 'private@example.com', '--format=json'))
assert disabled['effective_block'] is False and disabled['policy']['reason'] == 'disabled'
diagnostics = drush('postmark-webhooks:diagnostics', '--format=json')
assert json.loads(diagnostics)['secret_configured'] is False
assert 'private@example.com' not in diagnostics
assert json.loads(diagnostics)['intake']['accepted']['total'] == 0
print('Drush discovery, aliases, JSON, disabled policy and unsupported paths pass.')
