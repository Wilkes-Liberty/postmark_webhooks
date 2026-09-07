# Integration events

Issue #3621232. Other modules can already call `decide()`. This contract
notifies them after accepted webhooks and suppression changes commit.

## Defaults

Integration events are off until `integration_events_enabled` is true.
Intake and suppression stay synchronous. Delivery runs after commit on
cron, or with `drush postmark-webhooks:outbox-dispatch`. Inspect rows
with `drush postmark-webhooks:outbox`; that command does not deliver.
Subscriber failure does not undo accepted evidence.

## Event types

Version 1 JSON keys: `type`, `version`, `eventKey`, `source`, `occurred`,
`timeBasis`, `recipient`, `reason`, `suppressed`.

| Type | When |
| --- | --- |
| `webhook_accepted` | A unique webhook was stored |
| `suppression_changed` | Durable suppression evidence was inserted or replaced |

Duplicate retries of the same `eventKey` do not enqueue another
notification. A rolled-back transaction leaves no outbox row. Raw webhook
bodies and credentials are never included.

`recipient` is for the subscriber. Drush inspect and this module's logs
omit it. Treat it as personal data.

## Delivery

Delivery is at-least-once. Consumers must treat `eventKey` plus `type` as
the idempotency key. This module does not promise exactly-once external
side effects.

Cron delivers a bounded batch under a lock. Subscriber failures retry
with backoff. Invalid stored payloads and unsupported event versions
fail immediately and stay inspectable for replay. `replay()` requeues a
failed or delivered row. Delivered rows expire under
`integration_events_retention_seconds`.

```php
function mymodule_postmark_webhooks_integration_event(\Drupal\postmark_webhooks\Integration\IntegrationEvent $event): void {
  // Idempotent workflow. Throw only to retry delivery.
}
```

```sh
drush postmark-webhooks:outbox --format=json
drush postmark-webhooks:outbox-dispatch --format=json
```
