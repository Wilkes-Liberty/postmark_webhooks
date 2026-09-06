<?php

namespace Drupal\postmark_webhooks\Suppression;

/**
 * Immutable delivery-policy result for integrations and operator tools.
 */
final class SuppressionDecision implements \JsonSerializable {

  /**
   * Constructs a decision; NULL expiry means permanent when suppressed.
   */
  public function __construct(
    public readonly bool $suppressed,
    public readonly string $reason,
    public readonly ?int $expires = NULL,
    public readonly ?string $evidence = NULL,
    public readonly ?int $occurred = NULL,
    public readonly ?string $timeBasis = NULL,
  ) {}

  /**
   * {@inheritdoc}
   */
  public function jsonSerialize(): array {
    return get_object_vars($this);
  }

}
