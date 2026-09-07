<?php

declare(strict_types=1);

namespace Drupal\postmark_webhooks\Integration;

use Drupal\postmark_webhooks\Suppression\SuppressionStore;

/**
 * Version 1 integration event for accepted webhooks and suppression changes.
 */
final class IntegrationEvent implements \JsonSerializable {

  public const VERSION = 1;

  public const WEBHOOK_ACCEPTED = 'webhook_accepted';

  public const SUPPRESSION_CHANGED = 'suppression_changed';

  /**
   * Constructs a typed event. Recipients are for subscribers, not logs.
   */
  public function __construct(
    public readonly string $type,
    public readonly string $eventKey,
    public readonly string $serverId,
    public readonly string $messageStream,
    public readonly int $occurred,
    public readonly string $timeBasis,
    public readonly string $recipient,
    public readonly ?string $reason,
    public readonly ?bool $suppressed,
  ) {
    if (!in_array($type, [self::WEBHOOK_ACCEPTED, self::SUPPRESSION_CHANGED], TRUE)) {
      throw new \InvalidArgumentException('Unknown integration event type.');
    }
    if (!preg_match('/^[a-f0-9]{64}$/D', $eventKey)) {
      throw new \InvalidArgumentException('Invalid event identity.');
    }
    if (strlen($serverId) > 20 || str_contains($serverId, "\0")
      || strlen($messageStream) > 255 || str_contains($messageStream, "\0")) {
      throw new \InvalidArgumentException('Invalid source labels.');
    }
    if ($recipient === '' || str_contains($recipient, "\0")) {
      throw new \InvalidArgumentException('Invalid recipient.');
    }
  }

  /**
   * Builds an event from a stored intake row.
   */
  public static function fromAccepted(array $event, bool $suppressionChanged): self {
    $type = $suppressionChanged ? self::SUPPRESSION_CHANGED : self::WEBHOOK_ACCEPTED;
    $reason = $suppressionChanged ? SuppressionStore::reason($event) : NULL;
    $suppressed = NULL;
    if ($suppressionChanged && $reason !== NULL) {
      $suppressed = !str_starts_with($reason, 'release:');
    }
    $occurred = ($event['time_basis'] ?? 'legacy') === 'legacy' ? (int) $event['created'] : (int) $event['occurred'];
    return new self(
      $type,
      $event['event_key'],
      (string) ($event['server_id'] ?? ''),
      (string) ($event['message_stream'] ?? ''),
      $occurred,
      (string) ($event['time_basis'] ?? 'legacy'),
      mb_strtolower(trim((string) $event['recipient'])),
      $reason,
      $suppressed,
    );
  }

  /**
   * JSON keys contain no secrets or raw webhook bodies.
   */
  public function jsonSerialize(): array {
    return [
      'type' => $this->type,
      'version' => self::VERSION,
      'eventKey' => $this->eventKey,
      'source' => [
        'serverId' => $this->serverId,
        'messageStream' => $this->messageStream,
      ],
      'occurred' => $this->occurred,
      'timeBasis' => $this->timeBasis,
      'recipient' => $this->recipient,
      'reason' => $this->reason,
      'suppressed' => $this->suppressed,
    ];
  }

  /**
   * Idempotent outbox fingerprint for one logical notification.
   */
  public function fingerprint(): string {
    return hash('sha256', json_encode([$this->type, $this->eventKey], JSON_THROW_ON_ERROR));
  }

}
