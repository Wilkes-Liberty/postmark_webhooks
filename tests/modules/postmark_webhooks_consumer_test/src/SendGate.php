<?php

declare(strict_types=1);

namespace Drupal\postmark_webhooks_consumer_test;

use Drupal\postmark_webhooks\Source\SourceContext;
use Drupal\postmark_webhooks\Suppression\SuppressionPolicyInterface;

/**
 * Example 1.x consumer: allow or refuse using only the public policy interface.
 */
final class SendGate {

  /**
   * Constructs the example consumer.
   */
  public function __construct(private readonly SuppressionPolicyInterface $policy) {}

  /**
   * Whether this module's policy would allow sending to one mailbox.
   */
  public function allows(string $recipient, ?SourceContext $source = NULL): bool {
    return !$this->policy->decide($recipient, $source)->suppressed;
  }

}
