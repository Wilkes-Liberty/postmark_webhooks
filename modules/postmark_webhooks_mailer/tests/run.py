#!/usr/bin/env python3
"""Run adapter tests, allowing only documented upstream deprecation messages."""
import json
import os
from pathlib import Path
import re
import subprocess
import sys
import tempfile
import xml.etree.ElementTree as ET

root = Path(sys.argv[1]).resolve()
php = sys.argv[2] if len(sys.argv) > 2 else 'php'
tests = Path(__file__).resolve().parent
patterns = json.loads((tests / 'upstream-deprecations.json').read_text())
command = [php, str(root / 'vendor/bin/phpunit')]
major = int(re.search(r'PHPUnit (\d+)', subprocess.check_output(command + ['--version'], text=True)).group(1))
args = ['-c', 'web/core', str(tests)]
with tempfile.TemporaryDirectory(prefix='postmark-mailer-') as scratch:
    scratch = Path(scratch)
    if major < 10:
        ignore = scratch / 'upstream.txt'
        core_ignore = root / 'web/core/.deprecation-ignore.txt'
        lines = core_ignore.read_text() if core_ignore.exists() else ''
        ignore.write_text(lines + '\n' + '\n'.join('~' + p.replace('~', r'\~') + '~' for p in patterns))
        env = dict(os.environ, SYMFONY_DEPRECATIONS_HELPER='ignoreFile=' + str(ignore))
        sys.exit(subprocess.run(command + args, cwd=root, env=env).returncode)

    baseline = scratch / 'upstream.xml'
    junit = scratch / 'results.xml'
    result = subprocess.run(command + args + ['--generate-baseline', str(baseline), '--log-junit', str(junit)], cwd=root)
    if not junit.exists() or not baseline.exists():
        sys.exit(result.returncode or 1)
    suites = ET.parse(junit).getroot()
    if any(int(s.get('failures', '0')) or int(s.get('errors', '0')) for s in suites.iter('testsuite')):
        sys.exit(result.returncode or 1)
    issues = ET.parse(baseline).getroot()
    for file in issues.findall('file'):
        origin = (baseline.parent / file.get('path')).resolve()
        if not (origin.is_relative_to(root / 'web/core') or origin.is_relative_to(root / 'web/modules/contrib/symfony_mailer')):
            raise SystemExit('Unexpected deprecation origin: ' + str(origin))
        for issue in file.iter('issue'):
            if not any(re.fullmatch(pattern, issue.text or '') for pattern in patterns):
                raise SystemExit('Unexpected upstream deprecation: ' + (issue.text or ''))
    # Functional failures and unrecognized notices above remain fatal.
    # Known upstream notices remain visible in the original test output.
    sys.exit(0 if result.returncode in (0, 1) else result.returncode)
