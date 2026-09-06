# 1.x public API compatibility contract

Issue #3621229. This is the supported extension surface for 1.x. Storage tables,
webhook intake internals and operator forms are not a compatibility promise.

## Public PHP types

| Type | Role |
| --- | --- |
| `Drupal\postmark_webhooks\Suppression\SuppressionPolicyInterface` | `decide()` before transport |
| `Drupal\postmark_webhooks\Suppression\SuppressionDecision` | Immutable result |
| `Drupal\postmark_webhooks\Source\SourceContext` | Trusted sending server/stream |

Inject the interface, not the implementation class. Service ID
`postmark_webhooks.suppression_policy` and the interface class name are aliases
of the same service.

```php
use Drupal\postmark_webhooks\Source\SourceContext;
use Drupal\postmark_webhooks\Suppression\SuppressionPolicyInterface;

final class SendGate {

  public function __construct(private readonly SuppressionPolicyInterface $policy) {}

  public function allows(string $recipient, ?SourceContext $source = NULL): bool {
    return !$this->policy->decide($recipient, $source)->suppressed;
  }

}
```

The kernel consumer `postmark_webhooks_consumer_test` is that example wired
through the interface alias.

## `decide()` contract

- First argument is one mailbox. The policy lowercases and trims it. Do not
  pass a To/Cc/Bcc list; callers parse recipients themselves.
- Second argument is optional `SourceContext`. Omit it to apply all matching
  evidence conservatively. Supply it only from trusted sending code that knows
  the actual transport server and stream. Never build it from recipient data or
  untrusted headers.
- `decide()` does not send mail, write suppression, or call Postmark.
- A malformed `SourceContext` throws `InvalidArgumentException` at construction.
  Core mail and the Mailer Plus adapter treat a non-`SourceContext` param as a
  block, not as an ignored source.

## Decision fields

`jsonSerialize()` keys, in this order: `suppressed`, `reason`, `expires`,
`evidence`, `occurred`, `timeBasis`. The payload contains no recipient, secret
or raw provider body.

| Field | Meaning |
| --- | --- |
| `suppressed` | Whether this module would block the message |
| `reason` | Machine code below |
| `expires` | Unix expiry, or NULL when suppressed permanently |
| `evidence` | Opaque digest of the winning evidence; not a mailbox |
| `occurred` | Provider or receipt time of that evidence |
| `timeBasis` | `provider`, `receipt`, `clamped`, or `legacy` |

NULL `expires` on a suppressed result means permanent. Disabled suppression
returns `suppressed: false` and `reason: disabled` with the other fields NULL.
An allowed mailbox returns `reason: no_active_suppression`. Invalid imported
`source_policies` return `suppressed: true` and `reason: invalid_source_policy`
so mail fails closed.

Supported suppressed reasons:

| Reason | Origin |
| --- | --- |
| `hard:HardBounce`, `hard:BadEmailAddress`, `hard:ManuallyDeactivated`, `hard:Unsubscribe` | Bounce |
| `soft:Transient`, `soft:SoftBounce`, `soft:DnsError`, `soft:MailboxFull`, `soft:MessageTooLarge` | Bounce, windowed |
| `spam:SpamComplaint`, `spam:SpamNotification` | Complaint, windowed when configured |
| `consent:ManualSuppression` | Subscription change / opt-out |

1.x may add reason codes. It will not rename or remove these without a
deprecation in a 1.x minor and a major-version removal.

## Core mail and Mailer Plus

Both adapters call `SuppressionPolicyInterface::decide()`. Core
`hook_mail_alter()` sets `$message['send']` to FALSE when any To/Cc/Bcc
recipient is suppressed, or when the recipient list or source param is invalid.
The optional `postmark_webhooks_mailer` adapter type-hints the same interface
and cancels the whole Mailer Plus message. Disabled suppression is disabled on
both paths. Direct Symfony transports remain uncovered unless the caller uses
this contract.

## Drush JSON

`drush postmark-webhooks:status` (`--format=json`) keys: `enabled`, `mail_path`,
`covered`, `effective_block`, `delivery_guaranteed`, `policy`. `policy` is the
decision object above. `effective_block` is true only when suppression is
enabled, the mail path is covered, and the policy is suppressed. Disabled
suppression never reports an enforced block. `delivery_guaranteed` is always
false.

`drush postmark-webhooks:diagnostics` keys: `enabled`, `secret_configured`,
`previous_secret_status`, `coverage`, `intake`. `previous_secret_status` is
`absent`, `invalid`, `active` or `expired`. `coverage` has `core`,
`mailer_plus` and `direct_symfony`. `intake` has `accepted`, `duplicate` and
`rejected`, each with `total` and `last_seen`. Diagnostics omit secrets and
mailboxes.

1.x may add JSON keys. It will not rename or remove these keys without
deprecation.

## Configuration

Supported exported keys on `postmark_webhooks.settings`: `enabled`,
`bounce_suppression_days`, `complaint_suppression_days`, `event_retention_days`,
`source_policies`. Webhook secrets, previous-secret overlap, source allowlists
and reconciliation tokens stay in `settings.php`, not configuration.

## Internal

Do not depend on table schemas, `SuppressionStore`, `SuppressionPolicy` (the
class), `SourcePolicy`, `PolicyPreview`, intake metrics storage, webhook
controllers, or operator forms as a public API. Those classes are marked
`@internal`. A 1.x minor may change them without a deprecation.

## 1.x compatibility

Until 2.0.0:

- Keep `decide()` arguments and return type.
- Keep the service ID and interface alias.
- Keep documented reason codes, JSON keys and config keys.
- Additions are allowed.
- Removals or renames need a 1.x deprecation and a 2.x break.

There is no supported downgrade from a newer 1.x schema to an older one.
See [installation-upgrade.md](installation-upgrade.md).
