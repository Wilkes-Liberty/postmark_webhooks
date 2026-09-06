<?php

declare(strict_types=1);

namespace Drupal\postmark_webhooks_reconcile;

/**
 * A sanitized provider failure without request headers or provider body text.
 */
final class ProviderReadException extends \RuntimeException {

  /**
   * Constructs a safe failure with optional retry guidance in seconds.
   */
  public function __construct(string $message, public readonly ?int $retryAfter = NULL) {
    parent::__construct($message . ($retryAfter !== NULL ? ' Retry after ' . $retryAfter . ' seconds.' : ''));
  }

}
