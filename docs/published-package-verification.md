# Published-package verification

Issue #3621227. Git checkout CI is not evidence that the Drupal.org archive
installs. `tests/fixtures/verify-published-package.sh` Composer-installs
`drupal/postmark_webhooks` from packages.drupal.org, refuses a git symlink, and
checks the dist SHA-1 against the ftp.drupal.org zip.

## Recorded archive

| Field | Value |
| --- | --- |
| Version | 1.0.0-alpha2 |
| Dist URL | https://ftp.drupal.org/files/projects/postmark_webhooks-1.0.0-alpha2.zip |
| SHA-1 | `e31e9f67b554cdaf35d04549d955310c0d1d3c30` |
| SHA-256 | `94c8ca8c8c698d9fd3e09bd62de2a4bf4664e076a2f73f91c92378f47f91f371` |
| Alpha1 SHA-1 | `df706da9dfc7fcaa3de161776dd8391e27ac655e` |

The published job on Drupal 11.4 / PHP 8.4 / PostgreSQL 16 runs:

1. Fresh Composer install of 1.0.0-alpha2 and its kernel, HTTP, reconciliation
   and Drush suite, then `drush cache:rebuild`.
2. Composer require of 1.0.0-alpha1, then Composer replace with 1.0.0-alpha2,
   then the published alpha2 upgrade kernel tests (251-row resumed
   `hook_update_N` batches, durable suppression backfill, unique event keys).

Those tests cover settings enable/disable, permission boundaries, legacy rows
and cache rebuild after updates. They do not send provider mail. Operator
installation, upgrade and rollback steps are in
[installation-upgrade.md](installation-upgrade.md).

## Real Postmark acceptance — not executed here

No dedicated Postmark test server token, webhook secret or approved test stream
was available in this environment. Production and shared staging must not be
used for this gate. Earlier live Delivery/Bounce/SpamComplaint checks from the
extraction cutover predate this published archive and are not this issue's
evidence. SubscriptionChange, credential rotation, delayed events, source
matching and live reconciliation GET were not re-run against a provider.

Maintainer reproduction, on a disposable site and a dedicated Postmark server:

1. Point the server webhook at `https://<disposable-host>/api/webhooks/postmark`
   with HTTP Basic Auth. Keep the password in settings, not config.
2. Send provider test events for Delivery, Bounce, SpamComplaint and
   SubscriptionChange to controlled `@example.com` recipients, or use
   Postmark's documented test tools.
3. Confirm duplicate MessageID retries, a delayed older event, source allowlist
   rejection, current/previous secret overlap, and a 401 that Postmark retries.
4. Run optional reconciliation as GET-only against that server; apply nothing
   that writes to Postmark.
5. Record HTTP statuses and Postmark's webhook health without secrets or
   recipient addresses. Link that public note from this file.

Until that run exists, this issue cannot be Fixed.
