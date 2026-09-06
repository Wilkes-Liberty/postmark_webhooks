# Postmark Webhooks

## Optional read-only provider reconciliation

Enable `postmark_webhooks_reconcile` only if operators need to recover missed
suppression evidence. The core receiver does not require an API token. Configure
server tokens outside exported configuration, for example in settings.php:

```php
$settings['postmark_webhooks.reconciliation_tokens'] = [
  '123' => getenv('POSTMARK_SERVER_TOKEN'),
];
```

The reader uses only Postmark's documented `GET /server` and suppression-dump
endpoints. It verifies the token's server ID, honors the intake source allowlist,
requires TLS validation and disables redirects. It never creates, deletes or
reactivates anything at Postmark. Only current provider suppression evidence is
imported locally; this is not a replay of historical delivery events.

Postmark documents inclusive date filters but no offset pagination for the dump.
Preview one date at a time, inspect the aggregate evidence differences, then apply
the returned review identifier:

```sh
drush postmark-webhooks:reconcile-preview 123 outbound 2026-09-01
drush postmark-webhooks:reconcile-apply REVIEW_ID
drush postmark-webhooks:reconcile-status REVIEW_ID
```

Each apply call processes at most 250 local records. Repeat it until status is
`complete`. The checkpoint and suppression updates commit together; interrupted
pages can be retried. Every apply fetches the provider dump again and requires its
canonical digest to match the reviewed data. Changed data requires a fresh
preview. Preview stores only source/date/digest/progress metadata, not recipient
dumps or tokens, and makes no suppression changes. Reviews expire after 24 hours;
cron removes expired metadata in batches of 250. CLI access is privileged host
access and should be limited to authorized operators.

Responses are bounded to 4 MiB and 10000 records per date. An oversized day is
refused without partial import; it requires a separately reviewed migration
approach. Rate-limit and provider errors preserve the checkpoint and omit remote
body content. Imported records use the same ordered state service as live intake,
so stale hard bounces cannot override newer releases and absent provider rows do
not clear local consent, complaint or suppression evidence.

API references: [suppression dump](https://postmarkapp.com/developer/api/suppressions-api)
and [server identity](https://postmarkapp.com/developer/api/server-api).

## Recipient privacy controls

The Reports menu has separate export and history-erasure forms. Grant
`export postmark recipient data` and `erase postmark recipient history` only to
operators authorized for those actions; neither permission grants the other.
Exports stream normalized event history and minimal current suppression as a JSON
attachment, in database pages of 250 rows. The export request is audited. Its
event boundary excludes later intake; current suppression can change while an
export is streamed. Protect downloaded files as recipient data.

History erasure requires confirmation and processes at most 250 rows per batch.
Each completed deletion batch commits with its audit record. An interrupted
operation can be reviewed and retried. Events received after the confirmation
snapshot remain for a later review.

Erasure deliberately retains the normalized mailbox, source, reason, evidence
digest and occurrence/time basis needed to prevent unwanted mail. It also retains
operator audit records. Deleting history must not silently restore consent or
reactivate delivery. Operators must account separately for backups, provider
records, exports and site-specific retention policy; this control does not claim
irreversible erasure of external copies.

New intake stores no provider description or raw body. Update 10006 removes those
legacy fields in restartable batches without changing normalized history or
suppression state. The export excludes those fields even before that update runs.

## Suppression inspector and recovery

The Reports menu includes an exact-address suppression inspector. Grant
`view postmark suppression` to trusted operators who need the effective decision,
expiry, and up to 50 durable records and 50 recent events. The view omits raw
payloads, descriptions and provider message identifiers. Addresses are submitted
in a form, not placed in lookup URLs.

Grant `recover postmark hard bounces` separately to operators allowed to confirm
that a mailbox problem is repaired. Recovery releases only HardBounce and
BadEmailAddress evidence for a known recipient/server/stream pair. Other sources,
complaints, manual suppressions and unsubscribe records remain protected. Unknown
sources, changed evidence and already released records are refused. No provider
setting is changed and no mail is sent.

The release and audit record commit together. The audit records actor, time,
action, target evidence key and a keyed recipient reference; it stores no raw
mailbox or free-text notes. These references are pseudonymous, not anonymous.
Audit records are retained independently of event-history cleanup. Run database
updates for the audit table introduced by update 10005.

## Policy preview and diagnostics

The settings page links to a read-only policy preview. Select the real mail path
and optionally provide a trusted sending server/stream pair. It reports whether
this module would block the message, the policy reason and expiry. It sends no
mail and changes no consent or suppression evidence. Disabled suppression never
appears as an enforced block; an unsupported mail path is identified explicitly.
An allowed result does not guarantee provider acceptance or delivery.

Drush 13 discovers the commands automatically. Use `--format=json` for structured
output, for example:

```sh
drush postmark-webhooks:status recipient@example.com --format=json
drush postmark-webhooks:status recipient@example.com --mail-path=mailer_plus --server-id=123 --message-stream=outbound --format=json
drush postmark-webhooks:diagnostics --format=json
```

Diagnostics expose credential readiness, known adapter coverage, and aggregate
accepted, duplicate and authenticated-rejection counts with last-seen timestamps.
The counters begin at installation of update 10004; they do not reconstruct past
traffic. Successful event storage and its accepted counter commit together.
Counters survive history retention and contain no mailbox, message or secret
labels. Rejection counters are best-effort during database outages, so malformed requests
still receive their deterministic client error. Rejections cover authenticated
payload/source errors, not authentication
failures, upstream proxy errors or database outages; use infrastructure logs for
those. The settings page and preview require the existing administration
permission. Run database updates when upgrading.

Receives Postmark bounce, spam, and delivery webhooks and suppresses outbound
Drupal mail to addresses that bounced or complained. This module does not send
mail. Pair it with [Postmark](https://www.drupal.org/project/postmark) or another
mail backend that uses Drupal's mail manager.

Requires PHP 8.3 or later and Drupal 10.3 or 11. The module appears in the
**Chronicle** package group on the Extend page.

## Installation and configuration

```sh
composer require drupal/postmark_webhooks:^1.0@alpha
drush en postmark_webhooks
```

Set a long random secret in `settings.php`, preferably from an environment
variable supplied by your hosting platform:

```php
$settings['postmark_webhooks.webhook_secret'] = getenv('POSTMARK_WEBHOOK_SECRET') ?: '';
```

The secret is never stored in exported configuration or displayed in the admin
form. An empty, missing or non-string active secret makes the endpoint return **503** and record
nothing. Missing or incorrect credentials return **401**.

To rotate without interrupting requests in flight, deploy the new active secret
and retain the old one temporarily in settings:

```php
$settings['postmark_webhooks.previous_webhook_secret'] = [
  'secret' => getenv('POSTMARK_WEBHOOK_PREVIOUS_SECRET') ?: '',
  'expires' => 1790000000, // Replace with an explicit Unix expiry timestamp.
];
```

Choose the shortest practical overlap, update the Postmark dashboard credential,
then remove the previous setting. The previous secret is accepted only while the
current time is strictly before the integer expiry. At expiry it returns 401.
Malformed previous settings are ignored; a valid active secret continues working.
A previous credential never substitutes for a missing or malformed active one.
Neither credential is read from Drupal configuration or included in diagnostics.
`WebhookCredentials::rotationStatus()` reports only absent, invalid, active or
expired; it does not reveal values. Expiry is fixed, never extended by requests.

In your Postmark server's message stream, configure bounce, spam complaint and
delivery webhooks with this URL shape, replacing the example host and password:

```text
https://postmark:URL_ENCODED_SECRET@example.com/api/webhooks/postmark
```

Postmark sends these credentials as HTTP Basic Auth. The password must match
the settings value; `postmark` is the conventional username. Use HTTPS, preserve
the `Authorization` header through your proxy, and keep credentials out of
logs. Copy the webhook URL from the admin form and add credentials in Postmark.
The URL is generated through
Drupal routing and includes the installation base path and trusted proxy context.
The displayed URL contains no credentials.

Restrict access at your firewall or reverse proxy to the current
[Postmark webhook IP addresses](https://postmarkapp.com/support/article/800-ips-for-firewalls#webhooks).
IP allowlisting complements authentication; it does not replace the secret.
Postmark does not send `X-Postmark-Signature` or support payload HMAC signing.
See [Postmark's webhook security documentation](https://postmarkapp.com/developer/webhooks/webhooks-overview).

Configure suppression and retention at
`/admin/config/services/postmark-webhook`. The required permission is
`administer postmark webhook settings`.

## Suppression rules

Suppression is enabled by default and runs through `hook_mail_alter()`.

| Event or bounce type | Behavior |
| --- | --- |
| Bounce: HardBounce, BadEmailAddress, ManuallyDeactivated, Unsubscribe | Suppress permanently using durable evidence |
| SpamComplaint, SpamNotification | Suppress without a time window by default; optionally limit with `complaint_suppression_days` |
| Bounce: Transient, SoftBounce, DnsError, MailboxFull, MessageTooLarge | Suppress for `bounce_suppression_days`, default 30; 0 disables soft-bounce suppression |
| Delivery, Open, Click, Subscribe, unknown events | Log only; do not clear previous suppression |

`complaint_suppression_days: 0` means permanent suppression. Cron retention
applies only to event history. Each cron invocation removes at most 250 expired
rows, oldest first; interrupted cleanup resumes on the next invocation without a
separate cursor. Concurrent runs may overlap safely. Backlogs can persist beyond
the configured retention age: schedule cron often enough to outpace intake, or
invoke the retention service repeatedly in a controlled maintenance job. Minimal per-recipient, source and reason evidence
lives in `postmark_suppression` and survives event deletion. The latest occurrence
for each reason is retained, so an old event cannot reset a temporary window.
Changing window settings re-evaluates this evidence; disabling suppression does
not delete it. Delivery and other log-only events never clear a block.

Recipient matching is case-insensitive, including historical mixed-case rows.
Incoming recipients are normalized to lowercase. Every To, Cc and Bcc recipient
is checked, including arrays of header values and quoted display names containing
commas. One suppressed recipient blocks the whole message; allowed recipients are
not silently removed. Malformed lists and unsupported group syntax block the
message without logging the raw address list. Prior cancellation by another
module is preserved. An allowed message keeps its original headers.
A suppressed message has its
`send` flag set to false; watchdog messages redact the mailbox as `*@domain`.

```sh
drush postmark-webhooks:status user@example.com
# Alias: pm-wh:status
```

## Event storage and retries

The `postmark_events` table stores the receipt time, event type, MessageID,
recipient, bounce type and description. The reserved `payload` column remains
NULL: raw webhook JSON is not stored. Treat the event table as personal data
and restrict database access and backups accordingly.

Retries with the same event identity return **200** without inserting another
row, including concurrent retries. See the identity contract below.
Malformed JSON, non-object payloads, missing event type or recipient, conflicting
recipient fields, invalid identifier types and oversized extracted strings return
**400** without storing a row. Body reads are limited to 1 MiB; larger bodies
return **413**. Description and name are limited to 512 Unicode characters.
Bounce events also require a nonempty `Type` without surrounding whitespace.
This is the classification used for suppression; rejecting incomplete bounces
before persistence leaves their provider identity available for a corrected retry.
Unknown, well-formed bounce types remain log-only for forward compatibility.
Other record types do not require a bounce type. Unknown metadata is ignored.
Authentication is checked before parsing.
Previously accepted incomplete bounces are not repaired automatically: their
retained identity still counts as a retry, and their missing classification
cannot be reconstructed from local history.

Configuration imports enforce the same numeric limits as the settings form:
soft-bounce windows 0–365 days, complaint windows and retention 0–3650 days.

## Known limitations

- Native Mailer Plus sending requires the optional `postmark_webhooks_mailer`
  adapter described below. Direct Symfony transports outside Drupal Mailer Plus
  remain outside this integration; callers must use the shared policy themselves.
- This is an event receiver, not an inbound email parser or mail sender. It
  The optional reconciliation module imports reviewed suppression evidence; it
  does not automatically synchronize or alter a provider suppression list.

## Development

Use GitHub pull requests in `Wilkes-Liberty/postmark_webhooks` for development,
CI, review, and merges. Mirror merged commits and authorized release tags
additively to Drupalcode. Drupal.org remains the canonical issue tracker; keep
issue numbers in branches, commits, and PR titles. Drupalcode issue forks can
provide public patch references, but are not a separate merge workflow.

From a Drupal checkout with this module installed and development dependencies:

```sh
vendor/bin/phpcs --standard=Drupal,DrupalPractice --extensions=php,module,install web/modules/contrib/postmark_webhooks
SIMPLETEST_BASE_URL=http://127.0.0.1:8888 SIMPLETEST_DB=pgsql://user:password@localhost/database vendor/bin/phpunit -c web/core web/modules/contrib/postmark_webhooks/tests
```

For HTTP tests, start a disposable site server from the Drupal web root with
`PHP_CLI_SERVER_WORKERS=4 php -S 127.0.0.1:8888 -t . .ht.router.php`. CI runs
this server and the full suite on Drupal 10.6, 11.3 and 11.4 with PostgreSQL 16,
plus an isolated Drupal 10.3 compatibility-floor job. MySQL 8.4, MariaDB 10.11
and SQLite run the same suite on Drupal 10.6/PHP 8.3 and Drupal 11.4/PHP 8.5.
See [docs/integration-verification.md](docs/integration-verification.md),
[docs/database-verification.md](docs/database-verification.md) and
[docs/published-package-verification.md](docs/published-package-verification.md).

HTTP coverage includes settings permissions, secret exclusion, Basic Auth and
retry handling through the real route. Routing-context tests cover root,
subdirectory and trusted proxy URL generation. A separate installed-site HTTP
fixture verifies actual subdirectory Basic Auth, idempotent intake, authenticated
settings and prefixed preview links. Drush commands are discovered on an installed
site, and the optional mailer/reconciliation integrations have their own CI steps.
Kernel coverage includes authentication, missing secret, malformed JSON,
event identity, concurrent retries, legacy upgrades, unrelated database failures,
NULL payload, retention and case-insensitive suppression.

## Support and license

Report issues in the [Drupal.org issue queue](https://www.drupal.org/project/issues/postmark_webhooks).
Source: [git.drupalcode.org](https://git.drupalcode.org/project/postmark_webhooks).

Maintainer: Jeremy Michael Cerda. Sponsor: [Wilkes & Liberty, LLC](https://wilkesliberty.com).
Licensed under GPL-2.0-or-later; see [LICENSE.txt](LICENSE.txt).

## Event identity and retries

New events use a unique SHA-256 identity key. Identity includes the record type,
normalized recipient, and supplied server and message stream. A provider `ID`
identifies the event when present. Otherwise the fallback uses `MessageID`, bounce
`Type`, and the applicable provider timestamp (`BouncedAt`, `DeliveredAt`,
`ReceivedAt`, or `ChangedAt`). Distinct kinds, recipients, sources, provider IDs,
and fallback timestamps remain separate. A retry with the same identity receives
HTTP 200 without adding an event. Unrelated database failures remain failures so
the provider can retry. No raw webhook payload is retained.

If the provider supplies neither an event ID nor a timestamp, otherwise identical
fallback fields cannot distinguish a new event from a retry. Source fields in
this key distinguish records; they are not authenticated source-policy controls.
Retry deduplication lasts while the event row is retained.

Run database updates when upgrading from alpha1. The upgrade preserves all legacy
rows, including duplicates, with a NULL identity key because alpha1 did not retain
the identifiers needed to reconstruct reliable identities. A replay of a legacy
event can therefore add one new keyed row. Events previously discarded by
message-only deduplication cannot be recovered from the local database.


## Suppression service and occurrence time

Inject `postmark_webhooks.suppression_policy` (or the interface alias
`Drupal\postmark_webhooks\Suppression\SuppressionPolicyInterface`) and call
`decide($recipient)` before transport. The immutable result exposes `suppressed`,
`reason`, `expires`, `evidence`, `occurred`, and `timeBasis`; it is JSON serializable
and contains no recipient or secret. NULL expiry on a suppressed result means
permanent. Disabled mode returns an unsuppressed `disabled` result. Presentation
belongs to the caller. Core mail and the legacy helper use this same policy.

```php
$decision = $policy->decide('recipient@example.com');
if ($decision->suppressed) {
  // Stop before passing the message to a mail transport.
}
```

Occurrence and receipt times are stored separately. Bounce and complaint events
use `BouncedAt`, delivery uses `DeliveredAt`, and subscription changes use
`ChangedAt`. Other events can supply `ReceivedAt`. Missing timestamps use receipt
time; migrated alpha rows use legacy receipt time. RFC 3339 timestamps with up to
nine fractional digits are accepted. Invalid dates and timestamps more than five
minutes ahead are rejected; smaller future skew is clamped to the same receipt time stored in `created`
and explicitly reported with the `clamped` time basis.
Older events cannot replace newer state. Equal times use the identity digest as
a deterministic tie breaker. Policy results identify the time basis used.

Run database updates after upgrading. Durable-state migration processes retained
history in batches of 250 and normalizes legacy recipients. It cannot reconstruct
events already discarded or purged by alpha1. Event insertion and suppression
updates commit together. Source fields support the explicit policy mappings
described below; the default remains site-wide.

## Source policies

The default is site-wide suppression, including legacy and unknown sources.
To scope a known stream, import an explicit mapping in
`postmark_webhooks.settings`:

```yaml
source_policies:
  - server_id: '23'
    message_stream: broadcast
    scope: source
```

`scope: global` explicitly retains site-wide behavior. Unmapped sources remain
global. Invalid or duplicate mappings are rejected on import; invalid active
mappings block mail when suppression is enabled. Existing installations without
this key retain the default behavior and need no data migration.

Trusted sending code can pass `new SourceContext('23', 'outbound')` as the second
argument to `SuppressionPolicyInterface::decide()`. For Drupal core mail, put that
object in `$params['postmark_webhooks_source']`. The context must match the actual
transport's server and stream; do not derive it from user-submitted headers or
recipient data. Without context, all source evidence applies conservatively.
Transactional and broadcast streams only diverge when explicitly mapped and the
caller supplies a reliable context. A malformed context blocks the whole message.

Source labels in a payload do not authenticate the source. To restrict this
endpoint's active and previous credentials to known sources, configure settings:

```php
$settings['postmark_webhooks.allowed_sources'] = [
  ['server_id' => '23', 'message_stream' => 'broadcast'],
];
```

With an allowlist, missing or unlisted source pairs return 403 without storage.
An empty list permits none; a malformed list returns 503. Omitting the setting
preserves the existing shared-endpoint behavior. Credential validation still
happens first. Keep this allowlist in trusted deployment settings, not webhook
metadata. Separate credentials per source are not implemented; all accepted
credentials share this allowlist.

## Subscription changes and recovery

Enable the Subscription Change webhook in Postmark when using these transitions.
The provider contract is documented at
https://postmarkapp.com/developer/webhooks/subscription-change-webhook.
The receiver requires a source, ChangedAt, supported Origin, boolean
SuppressSending, and a supported reason for suppressions. A null MessageID is
accepted for these events. Reactivations have no suppression reason. Invalid or
ambiguous transitions return 400 before claiming retry identity.

HardBounce adds durable hard-bounce evidence. SpamComplaint adds complaint
evidence under the configured complaint window. ManualSuppression adds permanent
consent protection, including recipient opt-out and administrator suppression.
Because the payload does not identify the reason being removed, reactivation
only releases older HardBounce/BadEmailAddress evidence from the same known
server and stream. It never clears complaint, unsubscribe or manual suppression.
Equal occurrence seconds conservatively retain the block; a release must be
strictly newer. A later hard bounce blocks again. Source mappings still determine
where the remaining evidence applies.

The minimal release marker survives history retention so a delayed older event
cannot undo recovery. Release markers never clear legacy evidence whose source
is unknown. Retained event history stores only the transition boolean, reason and
origin in addition to existing extracted fields, never raw JSON. Subscription
identity version 2 includes transition state so equal-time changes remain distinct.
Other event identities are unchanged.

Run database updates. Existing SubscriptionChange rows gain nullable transition
fields; prior versions discarded the details needed to reconstruct their state.
They remain history-only. A valid replay uses the corrected identity contract;
reconciliation is required for unavailable provider history.

## Optional Mailer Plus adapter

The receiver does not require a sender dependency. When using `drupal/symfony_mailer`
(Mailer Plus), install that package separately and enable `postmark_webhooks_mailer`.
The adapter uses the supported initialization/post-render callback API shared by
Mailer Plus 1.6 and 2.x. It checks the To/Cc/Bcc addresses that become the final
transport envelope, including headers added while rendering, and cancels the whole
message when any recipient is suppressed. Allowed messages keep their headers.
Disabled suppression remains disabled in this path too.

A native caller can set the trusted `postmark_webhooks_source` email parameter to
a `SourceContext`; missing context conservatively applies all source evidence.
Do not use untrusted message headers to assert a source. The final callback runs
after normal mail processors. Custom code that changes recipients after this
callback must enforce the shared policy itself.

The compatibility path may also invoke the core hook. An earlier cancellation
prevents further sending; the adapter emits no duplicate suppression log. Allowed
messages are checked again after rendering because processors can add recipients.
Within that final check, duplicate To/Cc/Bcc addresses are evaluated once.

CI tests Mailer Plus 1.6.2 and 2.0.2 on Drupal 10.6 and 11.4, Mailer Plus 2.0.2
on Drupal 11.3, and the core receiver without either optional dependency on each
supported branch. Integration tests use
real native and compatibility processing with a capture-only transport. They
assert blocked messages never execute transport and allowed headers are retained.
The optional test runner permits only the exact known upstream deprecation
messages listed in `modules/postmark_webhooks_mailer/tests/upstream-deprecations.json`.
New messages and functional failures fail the run; upstream notices remain visible
on PHPUnit 11. This does not disable deprecation checking for the receiver suite.
