#!/usr/bin/env python3
"""Write the disposable Composer fixture used by GitHub Actions."""

import json
import os
from pathlib import Path

core = os.environ['DRUPAL_CONSTRAINT']
audit_block = os.environ.get('AUDIT_BLOCK_INSECURE', 'true').lower() in ('1', 'true', 'yes')
manifest = {
    'name': 'test/postmark-compatibility',
    'repositories': [{'type': 'composer', 'url': 'https://packages.drupal.org/8'}],
    'require': {
        'composer/installers': '^2',
        'drush/drush': '^13',
        'drupal/core-recommended': core,
        'drupal/core-composer-scaffold': core,
        'drupal/core-dev': core,
    },
    'config': {
        'allow-plugins': {
            'composer/installers': True,
            'drupal/core-composer-scaffold': True,
            'symfony/runtime': True,
            'phpstan/extension-installer': True,
            'dealerdirect/phpcodesniffer-composer-installer': True,
        },
    },
    'extra': {
        'installer-paths': {
            'web/core': ['type:drupal-core'],
            'web/modules/contrib/{$name}': ['type:drupal-module'],
        },
        'drupal-scaffold': {'locations': {'web-root': 'web/'}},
    },
}
if not audit_block:
    # Isolated Drupal 10.3 floor job only. Supported-branch jobs keep
    # Composer's default insecure-package block.
    manifest['config']['audit'] = {'block-insecure': False}
if os.environ.get('MAILER_CONSTRAINT'):
    manifest['require']['drupal/symfony_mailer'] = os.environ['MAILER_CONSTRAINT']
target = Path(os.environ.get('POSTMARK_CI_DIR', '/tmp/postmark-ci'))
target.mkdir(parents=True, exist_ok=True)
(target / 'composer.json').write_text(json.dumps(manifest))
