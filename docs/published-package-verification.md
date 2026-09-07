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

## Real Postmark acceptance — 2026-09-07

Recorded against a dedicated Postmark **sandbox** server (DeliveryType
Sandbox, server ID 19007494, stream `outbound`) and a disposable Drupal 11 /
PHP 8.4 receiver. Production and shared application staging were not used.
Throwaway Basic Auth credentials lived in settings.php only. The temporary
public HTTPS hostname and the Postmark webhook configuration were removed
after the run.

### Webhook verification

`POST /webhooks` with `Verify: true` and HTTP Basic Auth (username `postmark`)
created an outbound webhook. `POST /webhooks/{id}/verify` then reported
Success true, "All 4 triggers verified successfully", each with StatusCode
200:

- Delivery
- Bounce
- SpamComplaint
- SubscriptionChange

`GET /webhooks/{id}` and `GET /webhooks/{id}/statistics` showed those four
trigger statuses as `verified`. Statistics for the following 24-hour window
(no recipient labels): Delivery 1/1, Bounce 1/1, SubscriptionChange 2/2,
SpamComplaint 0 live events (verify posts are not counted as stream events).

Postmark verify payloads use dummy `ServerID` values (23 and 1234 in this
run), not the real server ID. An `allowed_sources` allowlist locked to
19007494/`outbound` therefore returned HTTP 403 during verify and blocked
webhook save (API error 1364). Verify succeeded after that allowlist was
removed. Source matching itself still works: a mismatched allowlist rejects
intake with 403 and stores nothing.

### Provider events

| RecordType | How it was produced | Local evidence |
| --- | --- | --- |
| Delivery | Sandbox `POST /email` from a confirmed sender signature on this server | One `postmark_events` row, `server_id` 19007494, `time_basis` provider, MessageID prefix `d5cffe2a-c5b` |
| Bounce | `POST /email` to `hardbounce@bounce-testing.postmarkapp.com` | One Bounce row, MessageID prefix `0e56e774-b6e` |
| SubscriptionChange | Hard-bounce suppression plus `POST /message-streams/outbound/suppressions` (manual) | Bounce MessageID also stored as SubscriptionChange; manual change stored with an empty MessageID |
| SpamComplaint | Provider verify test tool only | Dummy `ServerID` 23/1234 rows from verify. Fake-bounce addresses do not support SpamComplaint. Sandbox never delivers to a real inbox, so a genuine complaint cannot be generated here. |

`sender@example.org` is not a Sender Signature on this account (API error
400). Recipients at `@example.com` were inactive on this stream (API error
406). `@example.org` accepted a sandbox Delivery. Sandbox messages are not
placed in real inboxes.

### Auth, duplicates, rotation, 401 recovery

- No Authorization: HTTP 401 with `WWW-Authenticate: Basic realm="postmark-webhook"`.
- Current settings secret: 200.
- Previous settings secret (rotation overlap): 200.
- Wrong password: 401, zero rows stored.
- The same Delivery MessageID posted twice: both HTTP 200, one stored row
  (`event_key` unique).
- With the Drupal secret swapped to an incorrect value, `POST /webhooks/{id}/verify`
  returned Success false, all four triggers StatusCode 401 Unauthorized, and
  trigger statuses `unverified`. Restoring the secret and verifying again
  returned all four StatusCode 200 and statuses `verified`.

Postmark does **not** retry HTTP 401 (permanent 4xx). Restore the credential
and re-verify. Delayed retries apply to 5xx, 408, 429 and network
failures. A delayed older event was stored locally with `time_basis` provider
from the payload timestamp; live 5xx retry backoff was not forced in this run.

### Reconciliation GET-only

`postmark_webhooks_reconcile` used the settings-only token for server
19007494. `GET /server` matched that ID. Unfiltered
`GET /message-streams/outbound/suppressions/dump` returned 3 rows (2
ManualSuppression, 1 HardBounce). The same dump with
`fromdate=2026-09-07&todate=2026-09-07` returned 0 even though those rows' `CreatedAt`
dates were 2026-09-07 — a provider date-filter quirk, recorded as-is.

`drush postmark-webhooks:reconcile-preview 19007494 outbound 2026-09-07`
completed with `provider_writes: false` and
`local_suppression_changed: false`. Apply was not run. Nothing was created,
deleted or reactivated at Postmark by reconciliation.

### Health (no secrets, no recipients)

`drush postmark-webhooks:health --format=json` reported the active secret
configured, intake counters without recipient labels, retention within
threshold, and a rotation warning only because the throwaway previous secret
used for the overlap test was near expiry. Endpoint reachability stays
`unproven` in local health; provider verify and statistics are the
reachability evidence above.

### Maintainer reproduction

1. Use a dedicated sandbox (or otherwise disposable) Postmark server. Do not
   point this procedure at production.
2. Point that server's webhook at `https://<disposable-host>/api/webhooks/postmark`
   with HTTP Basic Auth. Keep the password in settings, not config.
3. Leave `allowed_sources` unset while Postmark verifies the webhook; dummy
   verify `ServerID` values will not match a real-server allowlist.
4. Send sandbox Delivery to a recipient that is not suppressed, a fake hard
   bounce to `hardbounce@bounce-testing.postmarkapp.com`, and a manual
   suppression for SubscriptionChange. Use Postmark verify for SpamComplaint.
5. Confirm duplicate MessageID storage, current/previous secret overlap, source
   allowlist 403, and a provider-visible 401 on verify followed by recovery
   re-verify. Postmark will not retry the 401 itself.
6. Run optional reconciliation as GET-only against that server; do not apply
   an import and do not POST suppressions except as an explicit
   SubscriptionChange fixture.
7. Record HTTP statuses and Postmark webhook health without secrets or
   recipient addresses. Remove the temporary webhook when finished.
