# Scheduled reconciliation and drift reports

Issue #3621233. Manual preview and apply from #3621136 stay the only way to
import provider evidence. The optional scheduler adds reviewable drift reports
around the same GET-only reader.

## Defaults

Scheduled scans are off until an operator enables them and lists sources.
Tokens remain in `settings.php`. Cron never applies a reviewed import, never
deletes local suppression, and never writes to Postmark.

## What is reported

Each completed UTC date produces one report with:

- `status`: `complete`, `empty`, or `incomplete`
- difference counts: `new_evidence`, `newer_evidence`, `unchanged_or_older`
- `provider_total` and a digest of provider event identities
- `absence_clears_local`: always false

An empty dump is `empty`, not incomplete. Rate limits, timeouts, missing
tokens, policy denials and invalid dumps are `incomplete` and keep a
reason code. Oversized days (over 4 MiB or 10000 rows) are incomplete and
are not retried in a loop. Reports never include recipients, secrets or
provider bodies.

Absence of a provider row does not mean a local complaint, manual
suppression or unsubscribe should be cleared. Live webhook intake can
arrive during a scan; both paths use the same ordered suppression store.

## Scheduling

| Setting | Default | Meaning |
| --- | --- | --- |
| `enabled` | false | Opt-in |
| `lookback_days` | 1 | Yesterday back through this many completed UTC days |
| `interval_seconds` | 86400 | Wait after a finished window before scanning again |
| `dates_per_run` | 1 | Bounded page of dates per source per cron run |
| `report_retention_seconds` | 604800 | Expire reports in batches of 250; 0 keeps them |
| `alert_cooldown_seconds` | 3600 | Identical drift hooks wait this long |
| `sources` | [] | `server_id` and `message_stream` pairs |

Each source is locked so overlapping cron workers cannot duplicate a run.
Interrupted windows resume from the stored date cursor. Today is not
scanned because that provider day is still open.

## Commands

```sh
drush postmark-webhooks:reconcile-preview 123 outbound 2026-09-01
drush postmark-webhooks:reconcile-apply REVIEW_ID
drush postmark-webhooks:reconcile-scan --format=json
drush postmark-webhooks:reconcile-drift --format=json
```

`reconcile-scan` is the same bounded tick cron uses. Importing still
requires an explicit preview and apply.

Other modules may implement `hook_postmark_webhooks_drift_report()`. This
module does not send mail. Hook failures are logged and do not fail the
report.
