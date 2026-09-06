<?php

declare(strict_types=1);

namespace Drupal\postmark_webhooks\Diagnostics;

use Drupal\Core\Database\Connection;
use Drupal\Core\Database\DatabaseException;

/**
 * Fixed-cardinality intake totals; never stores a recipient or credential.
 *
 * @internal
 */
final class IntakeMetrics {

  /**
   * Constructs the metrics store.
   */
  public function __construct(private readonly Connection $database) {}

  /**
   * Counts a rejection without changing a deterministic client error to 5xx.
   */
  public function recordRejection(int $now): bool {
    $transaction = $this->database->startTransaction();
    try {
      $this->record('rejected', $now);
    }
    catch (DatabaseException $exception) {
      $transaction->rollBack();
      return FALSE;
    }
    unset($transaction);
    return TRUE;
  }

  /**
   * Atomically records a successful intake, retry, or authenticated rejection.
   */
  public function record(string $outcome, int $now): void {
    if (!in_array($outcome, ['accepted', 'duplicate', 'rejected'], TRUE)) {
      throw new \InvalidArgumentException('Unknown intake outcome.');
    }
    $this->database->merge('postmark_intake_metrics')
      ->insertFields(['total' => 1, 'last_seen' => $now])
      ->key('outcome', $outcome)
      ->expression('total', 'total + 1')
      ->expression('last_seen', 'CASE WHEN last_seen < :now THEN :now ELSE last_seen END', [':now' => $now])
      ->execute();
  }

  /**
   * Returns counters collected since this feature was installed.
   */
  public function snapshot(): array {
    $result = array_fill_keys(['accepted', 'duplicate', 'rejected'], ['total' => 0, 'last_seen' => NULL]);
    if (!$this->database->schema()->tableExists('postmark_intake_metrics')) {
      return $result;
    }
    foreach ($this->database->select('postmark_intake_metrics', 'm')->fields('m')->execute() as $row) {
      $result[$row->outcome] = ['total' => (int) $row->total, 'last_seen' => (int) $row->last_seen];
    }
    return $result;
  }

}
