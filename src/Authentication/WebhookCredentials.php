<?php

declare(strict_types=1);

namespace Drupal\postmark_webhooks\Authentication;

use Drupal\Core\Site\Settings;

/**
 * Validates settings-only Basic Auth credentials during secret rotation.
 */
final class WebhookCredentials {

  /**
   * Constructs an immutable snapshot of the configured credentials.
   */
  private function __construct(
    private readonly mixed $active,
    private readonly mixed $previous,
  ) {}

  /**
   * Reads credentials exclusively from settings, never exported configuration.
   */
  public static function fromSettings(): self {
    return new self(
      Settings::get('postmark_webhooks.webhook_secret'),
      Settings::get('postmark_webhooks.previous_webhook_secret'),
    );
  }

  /**
   * Whether the required active credential is configured correctly.
   */
  public function isConfigured(): bool {
    return is_string($this->active) && $this->active !== '';
  }

  /**
   * Returns the previous-secret expiry timestamp, or NULL if none is usable.
   */
  public function previousExpiresAt(): ?int {
    if (!is_array($this->previous)
      || !is_string($this->previous['secret'] ?? NULL)
      || $this->previous['secret'] === ''
      || !is_int($this->previous['expires'] ?? NULL)
      || $this->previous['expires'] <= 0) {
      return NULL;
    }
    return $this->previous['expires'];
  }

  /**
   * Returns readiness without exposing either credential or its expiry.
   */
  public function rotationStatus(int $now): string {
    if ($this->previous === NULL) {
      return 'absent';
    }
    if (!is_array($this->previous)
      || !is_string($this->previous['secret'] ?? NULL)
      || $this->previous['secret'] === ''
      || !is_int($this->previous['expires'] ?? NULL)
      || $this->previous['expires'] <= 0) {
      return 'invalid';
    }
    return $now < $this->previous['expires'] ? 'active' : 'expired';
  }

  /**
   * Compares both credentials and accepts the previous one only before expiry.
   */
  public function accepts(?string $provided, int $now): bool {
    $rotation = $this->rotationStatus($now);
    $active = $this->isConfigured() ? $this->active : '';
    $previous = in_array($rotation, ['active', 'expired'], TRUE) ? $this->previous['secret'] : '';
    // Evaluate both comparisons before choosing a result. Never log values.
    $active_match = hash_equals($active, $provided ?? '');
    $previous_match = hash_equals($previous, $provided ?? '');
    return $this->isConfigured() && $provided !== NULL
      && ($active_match || ($rotation === 'active' && $previous_match));
  }

}
