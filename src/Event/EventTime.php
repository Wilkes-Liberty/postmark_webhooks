<?php

namespace Drupal\postmark_webhooks\Event;

/**
 * Applies one occurrence-time contract to webhook ingestion.
 */
final class EventTime {

  /**
   * Returns occurrence time and the basis used for suppression windows.
   */
  public static function resolve(array $data, int $received): array {
    $field = match ($data['RecordType']) {
      'Bounce', 'SpamComplaint' => 'BouncedAt',
      'Delivery' => 'DeliveredAt',
      'SubscriptionChange' => 'ChangedAt',
      default => 'ReceivedAt',
    };
    if (!isset($data[$field])) {
      return [$received, 'receipt'];
    }
    $value = $data[$field];
    if (!preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(?:\.\d{1,9})?(?:Z|[+-]\d{2}:\d{2})$/D', $value)) {
      throw new \InvalidArgumentException('Invalid occurrence time.');
    }
    // PHP parses at most six fractional digits; Postmark can send seven.
    $value = preg_replace('/(\.\d{6})\d+/', '$1', $value);
    try {
      $date = new \DateTimeImmutable($value);
    }
    catch (\Exception $exception) {
      throw new \InvalidArgumentException('Invalid occurrence time.', 0, $exception);
    }
    $errors = \DateTimeImmutable::getLastErrors();
    $time = $date->getTimestamp();
    if (($errors && ($errors['warning_count'] || $errors['error_count'])) || $time < 0 || $time > $received + 300) {
      throw new \InvalidArgumentException('Occurrence time outside supported bounds.');
    }
    return [min($time, $received), 'provider'];
  }

}
