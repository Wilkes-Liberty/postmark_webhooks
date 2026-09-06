# Postmark Webhooks

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
form. An empty or missing secret makes the endpoint return **503** and record
nothing. Missing or incorrect credentials return **401**.

In your Postmark server's message stream, configure bounce, spam complaint and
delivery webhooks with this URL shape, replacing the example host and password:

```text
https://postmark:URL_ENCODED_SECRET@example.com/api/webhooks/postmark
```

Postmark sends these credentials as HTTP Basic Auth. The password must match
the settings value; `postmark` is the conventional username. Use HTTPS, preserve
the `Authorization` header through your proxy, and keep credentials out of
logs. The admin form shows only the host and endpoint path.

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
| Bounce: HardBounce, BadEmailAddress, ManuallyDeactivated, Unsubscribe | Suppress while the event remains stored, without a time window |
| SpamComplaint, SpamNotification | Suppress without a time window by default; optionally limit with `complaint_suppression_days` |
| Bounce: Transient, SoftBounce, DnsError, MailboxFull, MessageTooLarge | Suppress for `bounce_suppression_days`, default 30; 0 disables soft-bounce suppression |
| Delivery, Open, Click, Subscribe, unknown events | Log only; do not clear previous suppression |

`complaint_suppression_days: 0` means no time limit. **Retention still applies:**
cron deletes events older than `event_retention_days` (default 90), including
hard bounces and complaints. Once a row is purged, it cannot suppress mail.
Set retention to 0 if suppression history must be kept indefinitely, or choose
a retention period consistent with your suppression policy.

Recipient matching is case-insensitive, including historical mixed-case rows.
Incoming recipients are normalized to lowercase. A suppressed message has its
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

Sequential retries with the same nonempty MessageID return **200** without
inserting another row. Requests without a MessageID are recorded separately.
Malformed JSON returns **400**. Authentication is checked before parsing.

## Known limitations

- Native Symfony Mailer transports can bypass `hook_mail_alter()`. Mail sent
  through those paths is **not suppressed** by this module. Verify your mail
  backend uses Drupal's mail manager; no Symfony Mailer event subscriber ships
  in this release.
- MessageID deduplication is global, not per event type. A later event sharing
  an earlier event's MessageID is ignored. Concurrent retries are not protected
  by a unique database constraint.
- Suppression is evaluated for one recipient, optionally with a display name.
  Multi-recipient To, Cc and Bcc lists are not individually filtered.
- This is an event receiver, not an inbound email parser or mail sender. It
  does not synchronize a Postmark server's suppression list.

## Development

From a Drupal checkout with this module installed and development dependencies:

```sh
vendor/bin/phpcs --standard=Drupal,DrupalPractice --extensions=php,module,install web/modules/contrib/postmark_webhooks
SIMPLETEST_DB=pgsql://user:password@localhost/database vendor/bin/phpunit -c web/core web/modules/contrib/postmark_webhooks/tests
```

Kernel coverage includes authentication, missing secret, malformed JSON,
MessageID retries, NULL payload, retention and case-insensitive suppression.

## Support and license

Report issues in the [Drupal.org issue queue](https://www.drupal.org/project/issues/postmark_webhooks).
Source: [git.drupalcode.org](https://git.drupalcode.org/project/postmark_webhooks).

Maintainer: Jeremy Michael Cerda. Sponsor: [Wilkes & Liberty, LLC](https://wilkesliberty.com).
Licensed under GPL-2.0-or-later; see [LICENSE.txt](LICENSE.txt).
