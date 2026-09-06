<?php

declare(strict_types=1);

namespace Drupal\postmark_webhooks\Source;

/**
 * Source supplied by trusted sending code, never inferred from mail recipients.
 */
final class SourceContext {

  /**
   * Constructs a complete server and stream context.
   */
  public function __construct(
    public readonly string $serverId,
    public readonly string $messageStream,
  ) {
    if (!preg_match('/^[0-9]{1,20}$/D', $serverId)
      || !mb_check_encoding($messageStream, 'UTF-8')
      || $messageStream === '' || mb_strlen($messageStream) > 255
      || str_contains($messageStream, "\0")) {
      throw new \InvalidArgumentException('Invalid source context.');
    }
  }

}
