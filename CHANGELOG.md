# Changelog

## [Unreleased]

- #3621132: Add explicit source-scoped policy and settings-bound intake source restrictions while preserving the site-wide default.

- #3621134: Bound each retention cleanup to 250 rows without changing durable suppression.

- #3621133: Accept a settings-only previous webhook secret until a fixed expiry; reject malformed active credentials safely.
- #3621125: Generate endpoint URLs through routing and verify HTTP authentication and settings access.

- #3621123: Check every To/Cc/Bcc recipient before core mail transport and reject malformed lists safely.
- Issue #3621122: Reject missing, empty or padded Bounce types before claiming event identity, so corrected retries can establish suppression.

- #3621121: Preserve minimal suppression evidence independently of event retention, including alpha upgrades.
- #3621124: Use validated provider occurrence times and preserve newer evidence on delayed events.
- #3621126: Expose one injectable, typed suppression policy for integrations and diagnostics.

- #3621122: Validate webhook object shape, extracted fields and body size before storage; validate imported suppression settings.

- #3621119: Preserve distinct webhook events and recipients with a versioned event identity.
- #3621120: Enforce retry deduplication atomically, preserving legacy rows during upgrade and propagating unrelated database failures.

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
