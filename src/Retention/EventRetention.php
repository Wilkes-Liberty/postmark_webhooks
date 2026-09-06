<?php

declare(strict_types=1);

namespace Drupal\postmark_webhooks\Retention;

use Drupal\Core\Database\Connection;

/**
 * Removes expired history in bounded, restartable batches.
 */
final class EventRetention {

  public const BATCH_SIZE = 250;

  /**
   * Constructs the event retention service.
   */
  public function __construct(private readonly Connection $database) {}

  /**
   * Deletes at most one batch; concurrent runs may safely select the same IDs.
   */
  public function purgeBefore(int $cutoff): int {
    $ids = $this->database->select('postmark_events', 'pe')
      ->fields('pe', ['eid'])
      ->condition('created', $cutoff, '<')
      ->orderBy('created')
      ->range(0, self::BATCH_SIZE)
      ->execute()->fetchCol();
    if (!$ids) {
      return 0;
    }
    // Recheck the cutoff and delete only the selected immutable primary keys.
    // Concurrent inserts are left for a later run, even if already expired.
    return $this->database->delete('postmark_events')
      ->condition('eid', $ids, 'IN')
      ->condition('created', $cutoff, '<')
      ->execute();
  }

}
