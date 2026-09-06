# Installation, upgrades and rollback

Issue #3621228. Operator steps for a fresh install and for upgrading a 1.0.0-alpha1
site. This is not a stable-release advertisement: install the current alpha with
`composer require drupal/postmark_webhooks:^1.0@alpha` until a 1.0.0 tag exists.

The Drupal.org archive is the package under test. Git checkout CI is recorded in
[integration-verification.md](integration-verification.md) and
[published-package-verification.md](published-package-verification.md).

## Fresh install

1. Back up the site database and `settings.php` before enabling a mail-related
   module on a site that already sends mail.
2. Require the published package and enable it:

   ```sh
   composer require drupal/postmark_webhooks:^1.0@alpha
   drush en postmark_webhooks -y
   drush cache:rebuild
   ```

3. Put a long random secret in `settings.php`. Do not export it in configuration:

   ```php
   $settings['postmark_webhooks.webhook_secret'] = getenv('POSTMARK_WEBHOOK_SECRET') ?: '';
   ```

4. Copy the webhook URL from `/admin/config/services/postmark-webhook` and add
   HTTP Basic Auth in the Postmark stream (username `postmark`, password equal to
   the settings value). Use HTTPS.
5. Confirm `drush postmark-webhooks:diagnostics --format=json` reports a usable
   active secret without printing it. An empty secret makes the endpoint return
   503 and store nothing.

Optional Mailer Plus and reconciliation modules stay disabled until needed.

## Backup before an upgrade

Treat `postmark_events` and `postmark_suppression` as personal data. A usable
rollback set is:

- a database dump taken immediately before `composer require` / `updatedb`
- the currently installed package version (`composer show drupal/postmark_webhooks`)
- `settings.php` secrets and allowlists (they are not in the database dump)
- exported configuration, if you manage it separately

Keep the dump off shared logs. Restoring only code or only the database can skip
or re-run updates.

## Upgrading from 1.0.0-alpha1

Alpha1 sites must run every `hook_update_N` through 10006. Schema-only updates
are idempotent. Batched updates 10002 and 10006 process 250 rows per batch and
resume from the last processed event id.

```sh
composer require drupal/postmark_webhooks:^1.0@alpha
drush updatedb -y
drush cache:rebuild
```

Do not add `--no-cache-clear` around this sequence. If `updatedb` is interrupted,
run the same command again; do not skip remaining updates or restore a partial
schema onto old code.

| Update | Effect |
| --- | --- |
| 10001 | Adds nullable unique `event_key`. Legacy rows keep a NULL key. |
| 10002 | Adds occurrence/source columns and backfills `postmark_suppression` from retained history in 250-row batches. |
| 10003 | Adds SubscriptionChange columns without reconstructing discarded transitions. |
| 10004 | Creates empty intake counters; it does not rebuild past totals. |
| 10005 | Creates the operator audit table. |
| 10006 | Clears legacy `description` text and `payload` bodies in 250-row batches. Normalized history and suppression stay. |

History already discarded by alpha1 retention cannot be reconstructed locally.
A replay of a legacy event can add one new keyed row because alpha1 did not keep
the identifiers used by the current identity contract.

## Interruption recovery

- `drush updatedb` stores batch sandbox progress. Re-running it continues 10002
  and 10006 instead of restarting from event 0.
- Each 10002 batch writes durable suppression for that page before the next page.
  Re-running a completed page is safe: later evidence cannot be overwritten by
  an older occurrence.
- 10006 only blanks description/payload. Re-running a completed page leaves
  empty/NULL values in place.
- Cron retention and recipient erasure also commit 250-row pages. An interrupted
  erasure can be reviewed and retried; events after the confirmation snapshot
  remain.

## Verification after install or upgrade

On a disposable copy of the upgraded database, or on the upgraded site after
mail is still paused if you are cutting over:

```sh
drush pm:list --filter=postmark_webhooks --status=enabled
drush config:get postmark_webhooks.settings
drush postmark-webhooks:diagnostics --format=json
drush postmark-webhooks:status recipient@example.com --format=json
drush cache:rebuild
```

Check that suppression remains enabled unless you chose otherwise, that
diagnostics omit secrets, and that a known suppressed mailbox still reports a
block. After 10006, new and scrubbed rows have an empty description and a NULL
payload.

## Restore-based rollback

There is no supported downgrade and no reverse update. To abandon an upgrade:

1. Stop webhook traffic (return 503 by removing the active secret, or pause the
   stream in Postmark).
2. Restore the pre-upgrade database dump.
3. Restore the pre-upgrade package files (`composer require` the previous
   exact version, currently `1.0.0-alpha1` if that is what you dumped).
4. Restore `settings.php` if secrets changed.
5. Rebuild caches and confirm diagnostics on the restored code.

Do not import an alpha2 schema dump into an alpha1 codebase, and do not run
alpha2 code against an unrestored alpha1 database. Either mismatch can skip
updates or apply them twice.

## Privacy: erasure is not consent restoration

| Retained after history erasure | Not a consent change |
| --- | --- |
| Normalized mailbox, source, reason, evidence digest, occurrence and time basis in `postmark_suppression` | Deleting `postmark_events` rows does not release a block |
| Operator audit rows (action, target, HMAC subject, uid, time; no raw mailbox) | Audit is independent of event retention |
| Provider copies, backups, and previously downloaded exports | Those stay outside this module |

Erasure must not silently restore consent or reactivate delivery. A later valid
SubscriptionChange reactivation can release only older HardBounce/BadEmailAddress
evidence from the same known source. Complaints, unsubscribes and manual
suppressions remain. Operators account separately for Postmark, backups and
exports.

New intake stores no provider description or raw body. Update 10006 removes
those legacy fields without changing suppression.

## Maintenance

Each cron run deletes at most 250 expired event rows. Durable suppression is
not deleted. Backlogs can remain older than the configured retention age if cron
cannot keep up. Disabling suppression does not delete evidence.

Recipient export streams 250-row pages and is audited. Protect the download.

## Command walkthrough

`tests/fixtures/verify-installation-docs.sh` follows these commands on
disposable SQLite sites under `/tmp/postmark-*`. It Composer-requires the
published `^1.0@alpha` package, enables the module, rebuilds caches and checks
diagnostics, then repeats from 1.0.0-alpha1 through `drush updatedb`. Do not
point it at production. It prints no secrets.

Walked through on a disposable Drupal 11.4 / PHP 8.5 / SQLite site: published
1.0.0-alpha2 enabled, caches rebuilt, diagnostics reported a configured secret
without printing it; a separate 1.0.0-alpha1 site then Composer-updated to
alpha2 and `drush updatedb` ran 10001 through 10006, leaving schema version
10006.
