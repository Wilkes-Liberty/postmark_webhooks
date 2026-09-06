# Integration verification

## Supported CI matrix (issue #3621225)

Verified against upstream lifecycle on 2026-09-06:

- Drupal 10.6.x is the oldest Drupal 10 branch still receiving security coverage.
- Drupal 11.3.x is security-supported through 2026-12-16.
- Drupal 11.4.x is the current Drupal 11 minor.
- PHP 8.3 is the module floor. PHP 8.5 is current and is supported by Drupal 11.3
  and 11.4. Drupal 10.6 supports PHP 8.3 and 8.4, not 8.5.

Each job installs an isolated Composer fixture, records the resolved PHP, Drupal
core, Drush and optional Mailer Plus versions in the GitHub Actions summary, then
runs Drupal coding standards, the kernel/HTTP suite (including upgrades), optional
reconciliation, Drush discovery and a real `/subdirectory` HTTP fixture on
PostgreSQL 16. Mailer jobs also run native and compatibility transport cancellation.

Supported jobs use Composer's default insecure-package block and `composer audit
--abandoned=report`. Security advisories fail the job; abandoned packages are
printed and do not fail it. Exact resolved versions come from the CI summary
for each run, not from this file.

| Job | Drupal | PHP | Mailer Plus | Audit |
| --- | --- | --- | --- | --- |
| d10.3-php8.3-floor | 10.3.* | 8.3 | none | isolated exception |
| d10.6-php8.3 | 10.6.* | 8.3 | none | yes |
| d10.6-php8.3-mailer-1.6 | 10.6.* | 8.3 | 1.6.2 | yes |
| d10.6-php8.4-mailer-2.0 | 10.6.* | 8.4 | 2.0.2 | yes |
| d11.3-php8.3 | 11.3.* | 8.3 | none | yes |
| d11.3-php8.5-mailer-2.0 | 11.3.* | 8.5 | 2.0.2 | yes |
| d11.4-php8.3 | 11.4.* | 8.3 | none | yes |
| d11.4-php8.5-mailer-1.6 | 11.4.* | 8.5 | 1.6.2 | yes |
| d11.4-php8.5-mailer-2.0 | 11.4.* | 8.5 | 2.0.2 | yes |

Mailer Plus 1.6.2 and 2.0.2 both declare Drupal `^10.3 || ^11`. 1.6.2 is the latest
1.6 release; 2.0.2 is the latest 2.x release as of this check. The matrix keeps
both lines on the oldest live Drupal 10 branch and on current Drupal 11.4, and
runs 2.x on 11.3. It is not a full Drupal × PHP × mailer Cartesian product.

### Advertised Drupal 10.3 floor

`composer.json` and `core_version_requirement` still allow Drupal 10.3. That
branch is past upstream security support. One isolated job (`d10.3-php8.3-floor`)
keeps the advertised floor from becoming an unchecked claim. That job sets
`audit.block-insecure: false` so Composer can install the old minor; the
exception applies only to this disposable fixture and is not a supported-branch
waiver.

## Fixture contents

Synthetic bounce, complaint and subscription fixtures follow Postmark's official
examples; their provenance is recorded beside the JSON files. Assertions cover
authentication denial, retry identity, private-field exclusion and transport
cancellation. Upgrade tests cover retained legacy evidence and restartable schema
or privacy updates. Reconciliation tests use a fake HTTP transport, not a live API.
No provider credentials or real mail are used.

## Operator-form checks

Live browser checks used a disposable site at a 375 × 812 viewport with the Stark
theme. Settings, policy preview, inspector, recovery confirmation, export and
history-erasure confirmation all fit without horizontal scrolling. Checks verified
accessible labels, recipient-to-mail-path focus order, keyboard submission,
evidence expansion, confirmation navigation/cancellation and repeated submissions.
The tests found and fixed oversized default input widths and private injected
properties lost during Drupal form-cache serialization. HTTP regressions preserve
the repeated-submit and CSRF contracts.

These checks do not claim a complete screen-reader audit or production-theme
certification. PostgreSQL is the measured database target; MySQL, MariaDB and
SQLite query plans and concurrency behavior remain unverified. Direct Symfony
transports and custom processors that mutate recipients after the adapter
callback remain outside the documented adapter boundary.
