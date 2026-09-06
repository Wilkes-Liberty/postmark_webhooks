<?php

namespace Drupal\postmark_webhooks\Event;

use Drupal\Component\Utility\EmailValidator;

/**
 * Validates extracted webhook fields before they reach identity or storage.
 */
final class WebhookPayload {

  public const MAX_BYTES = 1048576;

  /**
   * Decodes one JSON object, rejecting malformed or oversized extracted fields.
   */
  public static function decode(string $body): array {
    $object = json_decode($body, FALSE, 512, JSON_THROW_ON_ERROR | JSON_BIGINT_AS_STRING);
    if (!$object instanceof \stdClass) {
      throw new \InvalidArgumentException('Expected a JSON object.');
    }
    $data = (array) $object;
    $limits = [
      'RecordType' => 64,
      'Recipient' => 255,
      'Email' => 255,
      'MessageID' => 255,
      'Type' => 64,
      'Description' => 512,
      'Name' => 512,
      'MessageStream' => 255,
      'BouncedAt' => 64,
      'DeliveredAt' => 64,
      'ReceivedAt' => 64,
      'ChangedAt' => 64,
    ];
    foreach ($limits as $field => $limit) {
      if (array_key_exists($field, $data) && (!is_string($data[$field]) || mb_strlen($data[$field]) > $limit || str_contains($data[$field], "\0"))) {
        throw new \InvalidArgumentException('Invalid extracted string field.');
      }
    }
    foreach (['ID', 'ServerID'] as $field) {
      if (array_key_exists($field, $data) && ((!is_int($data[$field]) && !is_string($data[$field])) || !preg_match('/^[0-9]{1,20}$/D', (string) $data[$field]))) {
        throw new \InvalidArgumentException('Invalid provider identifier.');
      }
    }
    if (empty($data['RecordType']) || trim($data['RecordType']) !== $data['RecordType']) {
      throw new \InvalidArgumentException('Missing event type.');
    }
    $recipient = mb_strtolower(trim($data['Recipient'] ?? $data['Email'] ?? ''));
    if (!(new EmailValidator())->isValid($recipient)) {
      throw new \InvalidArgumentException('Invalid recipient.');
    }
    if (isset($data['Recipient'], $data['Email']) && mb_strtolower(trim($data['Email'])) !== $recipient) {
      throw new \InvalidArgumentException('Conflicting recipients.');
    }
    $data['Recipient'] = $recipient;
    return $data;
  }

}
