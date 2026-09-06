#!/usr/bin/env python3
"""Exercise a real installed site under a subdirectory, never production."""
import base64
import http.cookiejar
import json
from pathlib import Path
import subprocess
import stat
import sys
import urllib.error
import urllib.parse
import urllib.request

root = Path(sys.argv[1]).resolve()
base = sys.argv[2].rstrip('/') + '/subdirectory'
drush_command = [str(root / 'vendor/bin/drush'), '--root=' + str(root / 'web'), '--uri=' + base]


def drush(*args):
    return subprocess.check_output(drush_command + list(args), cwd=root, text=True)


# This marker and dedicated database prefix are created by drush-diagnostics.py.
settings = root / 'web/sites/default/settings.php'
if 'postmark_drush_' not in settings.read_text() and 'pm_diagnostics_' not in settings.read_text():
    raise SystemExit('Refusing a site without the disposable test database prefix.')
mode = stat.S_IMODE(settings.stat().st_mode)
settings.chmod(mode | stat.S_IWUSR)
try:
    with settings.open('a') as stream:
        stream.write("\n$settings['postmark_webhooks.webhook_secret'] = 'subdirectory-test-secret';\n")
finally:
    settings.chmod(mode)

endpoint = base + '/api/webhooks/postmark'
event = json.loads((Path(__file__).parent / 'postmark/bounce.json').read_text())
event['Email'] = 'subdirectory-contract@example.com'
payload = json.dumps(event).encode()


def post(headers):
    request = urllib.request.Request(endpoint, data=payload, headers=headers)
    try:
        return urllib.request.urlopen(request, timeout=20).status
    except urllib.error.HTTPError as error:
        return error.code


assert post({'Content-Type': 'application/json'}) == 401
auth = base64.b64encode(b'postmark:subdirectory-test-secret').decode()
headers = {'Content-Type': 'application/json', 'Authorization': 'Basic ' + auth}
assert post(headers) == 200
assert post(headers) == 200
count = drush('php:eval', "echo \\Drupal::database()->select('postmark_events')->condition('recipient','subdirectory-contract@example.com')->countQuery()->execute()->fetchField();")
assert int(count.strip()) == 1

drush('php:eval', "if (!\\Drupal\\user\\Entity\\Role::load('postmark_http_operator')) { \\Drupal\\user\\Entity\\Role::create(['id'=>'postmark_http_operator','label'=>'HTTP test operator'])->save(); }")
drush('role:perm:add', 'postmark_http_operator', 'administer postmark webhook settings')
drush('user:role:add', 'postmark_http_operator', 'admin')
# Drupal validates this destination against the request base path. Omitting the
# prefix produces a rejected external redirect on the installed-site fixture.
login = drush('user:login', '/subdirectory/admin/config/services/postmark-webhook', '--no-browser').strip()
opener = urllib.request.build_opener(urllib.request.HTTPCookieProcessor(http.cookiejar.CookieJar()))
opener.open(login, timeout=20).read()
page = opener.open(base + '/admin/config/services/postmark-webhook', timeout=20).read().decode()
assert endpoint in page
assert 'subdirectory-test-secret' not in page
assert '/subdirectory/admin/config/services/postmark-webhook/preview' in page
print('Real subdirectory routing, Basic Auth, duplicate intake and secret-free settings pass.')
