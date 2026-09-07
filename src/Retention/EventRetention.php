<?php

declare(strict_types=1);

namespace Drupal\postmark_webhooks\Retention;

use Drupal\Core\Database\Connection;
use Drupal\Core\Lock\LockBackendInterface;

/**
 * Removes expired history in bounded, restartable batches.
 *
 * @internal
 */
final class EventRetention {

  /**
   * Rows deleted in one batch. Measured; do not raise without new evidence.
   */
  public const BATCH_SIZE = 250;

  /**
   * Hard cap on batches in one drain, including CLI overrides.
   */
  public const MAX_BATCHES = 40;

  /**
   * Constructs the event retention service.
   */
  public function __construct(
    private readonly Connection $database,
    private readonly LockBackendInterface $lock,
  ) {}

  /**
   * Whether more than $limit expired history rows exist.
   */
  public function expiredExceeds(int $cutoff, int $limit): bool {
    if ($limit <= 0 || !$this->database->schema()->tableExists('postmark_events')) {
      return FALSE;
    }
    return (bool) $this->database->select('postmark_events', 'pe')
      ->fields('pe', ['eid'])
      ->condition('created', $cutoff, '<')
      ->orderBy('created')
      ->range($limit, 1)
      ->execute()
      ->fetchField();
  }

  /**
   * Oldest expired receipt time, or NULL. Uses a single indexed row.
   */
  public function oldestExpired(int $cutoff): ?int {
    if (!$this->database->schema()->tableExists('postmark_events')) {
      return NULL;
    }
    $created = $this->database->select('postmark_events', 'pe')
      ->fields('pe', ['created'])
      ->condition('created', $cutoff, '<')
      ->orderBy('created', 'ASC')
      ->range(0, 1)
      ->execute()
      ->fetchField();
    return $created === FALSE ? NULL : (int) $created;
  }

  /**
   * Deletes at most one batch; concurrent runs may safely select the same IDs.
   */
  public function purgeBefore(int $cutoff): int {
    if (!$this->database->schema()->tableExists('postmark_events')) {
      return 0;
    }
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

  /**
   * Drains several batches under a lock, row cap and optional wall-time budget.
   *
   * @return array
   *   deleted, batches, stopped (done, batches, time or lock) and
   *   oldest_expired.
   */
  public function drain(int $cutoff, int $max_batches, int $time_budget = 0, ?float $started = NULL): array {
    $max_batches = max(1, min(self::MAX_BATCHES, $max_batches));
    $started ??= microtime(TRUE);
    $result = [
      'deleted' => 0,
      'batches' => 0,
      'stopped' => 'done',
      'oldest_expired' => $this->oldestExpired($cutoff),
    ];
    if (!$this->lock->acquire('postmark_webhooks_retention', 120.0)) {
      $result['stopped'] = 'lock';
      return $result;
    }
    try {
      while ($result['batches'] < $max_batches) {
        if ($time_budget > 0 && (microtime(TRUE) - $started) >= $time_budget) {
          $result['stopped'] = 'time';
          break;
        }
        if (!$this->lock->acquire('postmark_webhooks_retention', 120.0)) {
          $result['stopped'] = 'lock';
          break;
        }
        $deleted = $this->purgeBefore($cutoff);
        $result['batches']++;
        $result['deleted'] += $deleted;
        if ($deleted < self::BATCH_SIZE) {
          $result['stopped'] = 'done';
          break;
        }
        if ($result['batches'] === $max_batches) {
          $result['stopped'] = 'batches';
        }
      }
    }
    finally {
      $this->lock->release('postmark_webhooks_retention');
    }
    $result['oldest_expired'] = $this->oldestExpired($cutoff);
    return $result;
  }

}
