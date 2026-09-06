<?php

declare(strict_types=1);

namespace Drupal\postmark_webhooks_reconcile;

use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\Database\Connection;
use Drupal\postmark_webhooks\Source\SourceContext;
use Drupal\postmark_webhooks\Suppression\SuppressionStore;

/**
 * Reviews immutable provider digests and checkpoints bounded local imports.
 */
final class Reconciliation {

  /**
   * Constructs reconciliation.
   */
  public function __construct(
    private readonly Connection $database,
    private readonly ProviderReader $reader,
    private readonly SuppressionStore $store,
    private readonly TimeInterface $time,
  ) {}

  /**
   * Saves only review metadata; never applies suppression or persists the dump.
   */
  public function preview(SourceContext $source, string $date): array {
    $comparison = $this->compare($source, $date);
    $job = [
      'job_id' => bin2hex(random_bytes(16)),
      'server_id' => $source->serverId,
      'message_stream' => $source->messageStream,
      'source_date' => $date,
      'digest' => $comparison['digest'],
      'position' => 0,
      'total' => $comparison['provider_total'],
      'created' => $this->time->getCurrentTime(),
      'status' => 'reviewed',
    ];
    $this->database->insert('postmark_reconciliation')->fields($job)->execute();
    return $job + [
      'differences' => $comparison['differences'],
      'reasons' => $comparison['reasons'],
      'local_suppression_changed' => FALSE,
      'provider_writes' => FALSE,
    ];
  }

  /**
   * Compares one date-filtered dump with local evidence and does not persist.
   */
  public function compare(SourceContext $source, string $date): array {
    $events = $this->reader->dump($source, $date);
    $differences = ['new_evidence' => 0, 'newer_evidence' => 0, 'unchanged_or_older' => 0];
    $reasons = [];
    $keys = [];
    foreach ($events as $event) {
      $reason = SuppressionStore::reason($event);
      $reasons[$reason] = ($reasons[$reason] ?? 0) + 1;
      $keys[] = hash('sha256', json_encode([$event['recipient'], $source->serverId, $source->messageStream, $reason], JSON_THROW_ON_ERROR));
    }
    $existing = [];
    foreach (array_chunk(array_unique($keys), 250) as $chunk) {
      $existing += $this->database->select('postmark_suppression', 's')
        ->fields('s', ['state_key', 'occurred', 'evidence'])
        ->condition('state_key', $chunk, 'IN')->execute()->fetchAllAssoc('state_key');
    }
    foreach ($events as $index => $event) {
      $current = $existing[$keys[$index]] ?? NULL;
      $newer = $current && ((int) $current->occurred < $event['occurred']
        || ((int) $current->occurred === $event['occurred'] && strcmp($current->evidence, $event['event_key']) < 0));
      $difference = !$current ? 'new_evidence' : ($newer ? 'newer_evidence' : 'unchanged_or_older');
      $differences[$difference]++;
    }
    return [
      'server_id' => $source->serverId,
      'message_stream' => $source->messageStream,
      'source_date' => $date,
      'digest' => $this->digest($events),
      'provider_total' => count($events),
      'status' => $events === [] ? 'empty' : 'complete',
      'incomplete_reason' => '',
      'differences' => $differences,
      'reasons' => $reasons,
      'local_suppression_changed' => FALSE,
      'provider_writes' => FALSE,
      'absence_clears_local' => FALSE,
    ];
  }

  /**
   * Applies at most 250 reviewed records and commits progress.
   */
  public function apply(string $id): array {
    $job = $this->job($id);
    if ($job['status'] === 'complete') {
      return $this->progress($job);
    }
    $events = $this->reader->dump(new SourceContext($job['server_id'], $job['message_stream']), $job['source_date']);
    if (!hash_equals($job['digest'], $this->digest($events))) {
      throw new \InvalidArgumentException('Provider data changed. Create and review a new preview before applying.');
    }
    $transaction = $this->database->startTransaction();
    try {
      // Re-read after acquiring the row lock: another worker may have committed
      // the next page while this process fetched the unchanged provider dump.
      $job = $this->job($id, TRUE);
      foreach (array_slice($events, (int) $job['position'], 250) as $event) {
        $this->store->record($event);
        $job['position']++;
      }
      $job['status'] = (int) $job['position'] >= (int) $job['total'] ? 'complete' : 'applying';
      $this->database->update('postmark_reconciliation')->fields([
        'position' => $job['position'],
        'status' => $job['status'],
      ])->condition('job_id', $id)->execute();
    }
    catch (\Throwable $exception) {
      $transaction->rollBack();
      throw $exception;
    }
    unset($transaction);
    return $this->progress($job);
  }

  /**
   * Returns bounded, non-recipient checkpoint metadata.
   */
  public function status(string $id): array {
    return $this->progress($this->job($id));
  }

  /**
   * Deletes at most 250 expired review records; suppression is untouched.
   */
  public function prune(): void {
    $ids = $this->database->select('postmark_reconciliation', 'j')->fields('j', ['job_id'])
      ->condition('created', $this->time->getCurrentTime() - 86400, '<=')->range(0, 250)->execute()->fetchCol();
    if ($ids) {
      $this->database->delete('postmark_reconciliation')->condition('job_id', $ids, 'IN')->execute();
    }
  }

  /**
   * Reads an unexpired review record, optionally locking it for a local page.
   */
  private function job(string $id, bool $lock = FALSE): array {
    if (!preg_match('/^[a-f0-9]{32}$/D', $id)) {
      throw new \InvalidArgumentException('Invalid reconciliation identifier.');
    }
    $query = $this->database->select('postmark_reconciliation', 'j')->fields('j')->condition('job_id', $id);
    if ($lock) {
      $query->forUpdate();
    }
    $job = $query->execute()->fetchAssoc();
    if (!$job || (int) $job['created'] <= $this->time->getCurrentTime() - 86400) {
      throw new \InvalidArgumentException('The review is missing or expired. Create a fresh preview.');
    }
    return $job;
  }

  /**
   * Hashes canonical identities independently of provider order and fetch time.
   */
  private function digest(array $events): string {
    $keys = array_column($events, 'event_key');
    sort($keys, SORT_STRING);
    return hash('sha256', json_encode($keys, JSON_THROW_ON_ERROR));
  }

  /**
   * Formats progress without provider content or recipient identifiers.
   */
  private function progress(array $job): array {
    return [
      'job_id' => $job['job_id'],
      'status' => $job['status'],
      'processed' => (int) $job['position'],
      'total' => (int) $job['total'],
      'provider_writes' => FALSE,
    ];
  }

}
