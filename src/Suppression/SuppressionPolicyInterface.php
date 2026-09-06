<?php

namespace Drupal\postmark_webhooks\Suppression;

use Drupal\postmark_webhooks\Source\SourceContext;

/**
 * Stable boundary for checking one normalized recipient before transport.
 */
interface SuppressionPolicyInterface {

  /**
   * Returns the effective decision without sending mail or changing state.
   */
  public function decide(string $recipient, ?SourceContext $source = NULL): SuppressionDecision;

}
