# Changelog

## [Unreleased]

- #3621236: Verify operator forms in Claro: associated instructions, named result regions, field-level filter errors, destructive confirmation controls and Cancel on erasure. Live screen-reader certification remains outstanding.
- #3621235: Add configurable multi-batch retention draining with a lock, optional wall-time budget, CLI drain command and oldest-expired backlog visibility. The 250-row batch size is unchanged. Durable suppression and operator audits are not deleted.
- #3621234: Add a permission-controlled MessageID timeline for retained delivery, bounce and complaint events. Lookup stays in the form, not URLs. Delivery is provider evidence and does not override suppression. The view does not claim to be a complete provider archive.
- #3621231: Add optional named source profiles in settings.php that bind independently rotated webhook credentials to server and stream pairs. Authenticate the credential first, then reject cross-profile payloads before storage. The shared secret remains the default; it is ignored while any profile is usable.
- #3621232: Add an opt-in transactional outbox for typed integration events after accepted webhooks and suppression changes. Delivery is at-least-once after commit; subscriber failure does not undo suppression. Duplicate webhooks and rolled-back transactions create no extra notifications.
- #3621233: Add opt-in scheduled read-only reconciliation previews and suppression drift reports. Cron scans completed UTC dates with per-source locks and resumable cursors, never applies imports, and does not infer consent from provider absence.
- #3621230: Add configurable webhook health evaluation, status-report and Drush output, and optional hook-based alerts that do not send mail. Quiet sites stay unknown unless an activity window is configured.
- #3621229: Document the 1.x public suppression contract, mark implementation classes internal, and add an external-consumer example with contract tests. Core mail and the Mailer Plus adapter keep using that interface.
- #3621228: Lead README and project-page copy with installation, correct event-storage and privacy claims, and consolidate the alpha1 upgrade runbook. The advertised Composer command remains `^1.0@alpha` until a stable package exists.
- #3621227: Verify the Drupal.org 1.0.0-alpha2 archive through Composer, including an alpha1-to-alpha2 file replacement. Live Postmark acceptance is still outstanding.
- #3621226: Verify installation, upgrades, concurrent intake, unique keys, privacy, retention and indexed lookups on MySQL 8.4, MariaDB 10.11 and SQLite in addition to PostgreSQL 16.
- #3621225: Run kernel, HTTP, upgrade, reconciliation and Drush checks on Drupal 10.6, 11.3 and 11.4 with PHP 8.3 and a current compatible PHP version. Keep Mailer Plus 1.6.2 and 2.0.2 on the supported matrix, isolate the advertised Drupal 10.3 floor from Composer audit blocking, and record resolved package versions in CI.

## [1.0.0-alpha2] - 2026-09-06

- #3621137: Verify real subdirectory HTTP deployment and complete responsive operator-form integration coverage.
- #3621136: Add optional read-only provider reconciliation with reviewed digests and atomic local import checkpoints.
- #3621135: Add audited recipient export and bounded history erasure while retaining suppression, and remove stored provider free text.
- #3621130: Add a protected recipient inspector and CSRF-confirmed, audited source-specific hard-bounce recovery.
- #3621131: Add read-only policy previews, structured Drush diagnostics and privacy-safe atomic intake counters.
- #3621128: Add an optional Mailer Plus adapter that checks final recipients before native and compatibility transport.
- #3621129: Apply ordered subscription suppression and source-specific hard-bounce recovery without clearing consent protections.
- #3621132: Add explicit source-scoped policy and settings-bound intake source restrictions while preserving the site-wide default.
- #3621134: Bound each retention cleanup to 250 rows without changing durable suppression.
- #3621133: Accept a settings-only previous webhook secret until a fixed expiry; reject malformed active credentials safely.
- #3621125: Generate endpoint URLs through routing and verify HTTP authentication and settings access.
- #3621123: Check every To/Cc/Bcc recipient before core mail transport and reject malformed lists safely.
- #3621121: Preserve minimal suppression evidence independently of event retention, including alpha upgrades.
- #3621124: Use validated provider occurrence times and preserve newer evidence on delayed events.
- #3621126: Expose one injectable, typed suppression policy for integrations and diagnostics.
- #3621122: Validate webhook object shape, fields, body size and imported settings. Reject missing, empty or padded Bounce types before claiming event identity so corrected retries can establish suppression.
- #3621119: Preserve distinct webhook events and recipients with a versioned event identity.
- #3621120: Enforce retry deduplication atomically, preserving legacy rows during upgrade and propagating unrelated database failures.

### Upgrading from alpha1

Back up the database, update the package and run `drush updatedb -y` followed by
`drush cache:rebuild`. Updates preserve retained event rows and backfill minimal
suppression evidence before scrubbing legacy provider descriptions and payloads.
History already discarded by alpha1 cannot be reconstructed locally. Hard-bounce
and complaint suppression now survives history retention. Review suppression
policy before resuming mail; recovered provider state is an optional, reviewed
operation. The Mailer Plus and reconciliation submodules remain opt-in.

This is an alpha release. PostgreSQL 16 is the verified database target; the
integration matrix covers Drupal 10.3/PHP 8.3 and Drupal 11/PHP 8.4.

## [1.0.0-alpha1] - 2026-09-05

- Initial standalone Postmark Webhooks release in the Chronicle package group.
- Receive bounce, spam and delivery events using HTTP Basic Auth and HTTPS.
- Keep the webhook secret in settings.php; return 503 when unconfigured.
- Suppress Drupal mail using configurable bounce and complaint windows, with
  case-insensitive recipient matching and redacted watchdog messages.
- Acknowledge repeated MessageIDs without another insert; omit raw payloads.
- Purge event history through cron with configurable retention.
- Provide settings UI, Drush status command and kernel regression tests.
- Document retention, deduplication and native Symfony Mailer limitations.
