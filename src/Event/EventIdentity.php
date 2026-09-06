<?php

namespace Drupal\postmark_webhooks\Event;

/**
 * Builds versioned event identities without retaining raw webhook payloads.
 */
final class EventIdentity {

  /**
   * Returns a stable key for a provider event or its documented fallback.
   */
  public static function key(array $data, string $recipient): string {
    $scope = [
      'v1',
      (string) ($data['ServerID'] ?? ''),
      (string) ($data['MessageStream'] ?? ''),
      (string) ($data['RecordType'] ?? ''),
      $recipient,
    ];
    if (isset($data['ID']) && (string) $data['ID'] !== '') {
      $identity = ['id', (string) $data['ID']];
    }
    else {
      $identity = [
        'fallback',
        (string) ($data['MessageID'] ?? ''),
        (string) ($data['Type'] ?? ''),
        (string) ($data['BouncedAt'] ?? $data['DeliveredAt'] ?? $data['ReceivedAt'] ?? $data['ChangedAt'] ?? ''),
      ];
    }
    return hash('sha256', json_encode([$scope, $identity], JSON_THROW_ON_ERROR));
  }

}
