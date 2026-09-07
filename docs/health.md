# Webhook health checks

Issue #3621230. Diagnostics remain available on demand. Health evaluation adds
thresholds, a status-report summary and optional alerts. This module does not
send mail.

## What is reported

| Check | Meaning |
| --- | --- |
| `secret` | Active settings-only webhook secret is present |
| `rotation` | Previous secret absent, active, expiring, expired or invalid |
| `intake_silence` | Last accepted intake versus an optional activity window |
| `retention_backlog` | Whether expired event rows exceed a warning threshold. The health report also includes `oldest_expired`, an indexed one-row probe |
| `source_health` | Always unknown. Counters have no source labels. Malformed source profiles report `malformed_profiles` because intake returns 503 |
| `endpoint_reachability` | Always `unproven` from local counters |
| `provider_delivery` | Optional hook; default `not_queried` |

No check includes a recipient, secret or raw provider body. Last accepted
intake is an aggregate timestamp.

Silence is `unknown` unless `health_expected_activity_seconds` is greater than
zero. Unknown checks do not raise overall severity. Quiet sites are not
reported broken.

Local accepted counters never prove that Postmark can reach the endpoint.
Another module may implement `hook_postmark_webhooks_provider_health()` to
report paused or available provider delivery.

## Commands

```sh
drush postmark-webhooks:health --format=json
drush postmark-webhooks:diagnostics --format=json
```

Cron evaluates health after retention cleanup. Identical unhealthy fingerprints
wait for `health_alert_cooldown_seconds` (default 1 hour). A cooldown of 0
emits only when the unhealthy set changes. Recovery is emitted once when
severity returns to `ok` or `unknown`. Notification delivery failures are
logged and do not fail the health report.

Other modules subscribe with `hook_postmark_webhooks_health_alert()`.
