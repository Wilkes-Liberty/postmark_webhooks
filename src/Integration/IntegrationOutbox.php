<?php

declare(strict_types=1);

namespace Drupal\postmark_webhooks\Integration;

use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Database\Connection;
use Drupal\Core\Database\IntegrityConstraintViolationException;
use Drupal\Core\Extension\ModuleHandlerInterface;
use Drupal\Core\Lock\LockBackendInterface;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;

/**
 * Transactional outbox for at-least-once integration delivery.
 *
 * @internal
 */
final class IntegrationOutbox {

  /**
   * Constructs the outbox.
   */
  public function __construct(
    private readonly Connection $database,
    private readonly ConfigFactoryInterface $configFactory,
    private readonly ModuleHandlerInterface $moduleHandler,
    private readonly LockBackendInterface $lock,
    private readonly LoggerChannelFactoryInterface $loggerFactory,
    private readonly TimeInterface $time,
  ) {}

  /**
   * Enqueues one logical notification in the current transaction.
   */
  public function enqueue(IntegrationEvent $event): void {
    if (!$this->enabled() || !$this->database->schema()->tableExists('postmark_integration_outbox')) {
      return;
    }
    $now = $this->time->getCurrentTime();
    $transaction = $this->database->startTransaction();
    try {
      $this->database->insert('postmark_integration_outbox')->fields([
        'fingerprint' => $event->fingerprint(),
        'type' => $event->type,
        'version' => IntegrationEvent::VERSION,
        'payload' => json_encode($event, JSON_THROW_ON_ERROR),
        'status' => 'pending',
        'attempts' => 0,
        'available_at' => $now,
        'created' => $now,
        'delivered_at' => 0,
        'last_error' => '',
      ])->execute();
    }
    catch (IntegrityConstraintViolationException $exception) {
      $transaction->rollBack();
    }
    unset($transaction);
  }

  /**
   * Delivers a bounded page of due rows.
   *
   * Subscriber failure does not roll back intake.
   */
  public function dispatch(?int $now = NULL): array {
    $now ??= $this->time->getCurrentTime();
    $result = ['attempted' => 0, 'delivered' => 0, 'failed' => 0];
    if (!$this->enabled() || !$this->database->schema()->tableExists('postmark_integration_outbox')) {
      return $result;
    }
    if (!$this->lock->acquire('postmark_webhooks_outbox', 120.0)) {
      return $result + ['skipped' => 'lock'];
    }
    try {
      $limit = max(1, min(250, (int) ($this->configFactory->get('postmark_webhooks.settings')->get('integration_events_batch') ?? 25)));
      $max_attempts = max(1, min(32, (int) ($this->configFactory->get('postmark_webhooks.settings')->get('integration_events_max_attempts') ?? 8)));
      $ids = $this->database->select('postmark_integration_outbox', 'o')
        ->fields('o', ['oid'])
        ->condition('status', 'pending')
        ->condition('available_at', $now, '<=')
        ->orderBy('oid')
        ->range(0, $limit)
        ->forUpdate()
        ->execute()
        ->fetchCol();
      foreach ($ids as $id) {
        $result['attempted']++;
        $row = $this->database->select('postmark_integration_outbox', 'o')
          ->fields('o')
          ->condition('oid', $id)
          ->execute()
          ->fetchAssoc();
        if (!$row || $row['status'] !== 'pending') {
          continue;
        }
        try {
          $event = $this->decode($row['payload']);
          $this->moduleHandler->invokeAll('postmark_webhooks_integration_event', [$event]);
          $this->database->update('postmark_integration_outbox')->fields([
            'status' => 'delivered',
            'attempts' => (int) $row['attempts'] + 1,
            'delivered_at' => $now,
            'last_error' => '',
          ])->condition('oid', $id)->execute();
          $result['delivered']++;
        }
        catch (\InvalidArgumentException $exception) {
          $this->failRow($id, (int) $row['attempts'] + 1, $now, $exception, TRUE);
          $result['failed']++;
        }
        catch (\Throwable $exception) {
          $attempts = (int) $row['attempts'] + 1;
          $failed = $attempts >= $max_attempts;
          $this->failRow($id, $attempts, $now, $exception, $failed);
          $result['failed']++;
        }
      }
      return $result;
    }
    finally {
      $this->lock->release('postmark_webhooks_outbox');
    }
  }

  /**
   * Requeues one failed or delivered row for another at-least-once attempt.
   */
  public function replay(int $oid, ?int $now = NULL): void {
    $now ??= $this->time->getCurrentTime();
    if (!$this->database->schema()->tableExists('postmark_integration_outbox')) {
      throw new \InvalidArgumentException('Integration outbox is not installed.');
    }
    $updated = $this->database->update('postmark_integration_outbox')->fields([
      'status' => 'pending',
      'available_at' => $now,
      'last_error' => '',
    ])->condition('oid', $oid)->condition('status', ['failed', 'delivered'], 'IN')->execute();
    if (!$updated) {
      throw new \InvalidArgumentException('That outbox row cannot be replayed.');
    }
  }

  /**
   * Lists inspectable rows without recipient or secret labels.
   */
  public function inspect(int $limit = 20): array {
    if (!$this->database->schema()->tableExists('postmark_integration_outbox')) {
      return [];
    }
    $limit = max(1, min(100, $limit));
    $result = $this->database->select('postmark_integration_outbox', 'o')
      ->fields('o', [
        'oid',
        'type',
        'version',
        'status',
        'attempts',
        'available_at',
        'created',
        'delivered_at',
        'last_error',
        'fingerprint',
      ])
      ->orderBy('oid', 'DESC')
      ->range(0, $limit)
      ->execute();
    $rows = [];
    while ($row = $result->fetchAssoc()) {
      $rows[] = [
        'oid' => (int) $row['oid'],
        'type' => $row['type'],
        'version' => (int) $row['version'],
        'status' => $row['status'],
        'attempts' => (int) $row['attempts'],
        'available_at' => (int) $row['available_at'],
        'created' => (int) $row['created'],
        'delivered_at' => (int) $row['delivered_at'] ?: NULL,
        'last_error' => $row['last_error'],
        'fingerprint' => $row['fingerprint'],
      ];
    }
    return $rows;
  }

  /**
   * Deletes delivered rows older than retention in batches of 250.
   */
  public function prune(?int $now = NULL): void {
    if (!$this->database->schema()->tableExists('postmark_integration_outbox')) {
      return;
    }
    $now ??= $this->time->getCurrentTime();
    $retention = (int) ($this->configFactory->get('postmark_webhooks.settings')->get('integration_events_retention_seconds') ?? 604800);
    if ($retention <= 0) {
      return;
    }
    $ids = $this->database->select('postmark_integration_outbox', 'o')->fields('o', ['oid'])
      ->condition('status', 'delivered')
      ->condition('delivered_at', $now - $retention, '<=')
      ->condition('delivered_at', 0, '>')
      ->orderBy('oid')
      ->range(0, 250)
      ->execute()
      ->fetchCol();
    if ($ids) {
      $this->database->delete('postmark_integration_outbox')->condition('oid', $ids, 'IN')->execute();
    }
  }

  /**
   * Whether operators enabled post-commit dispatch.
   */
  private function enabled(): bool {
    return (bool) ($this->configFactory->get('postmark_webhooks.settings')->get('integration_events_enabled') ?? FALSE);
  }

  /**
   * Bounded exponential backoff in seconds.
   */
  private function backoff(int $attempts): int {
    // Cap the exponent so 2 ** n cannot overflow a 32-bit int before min().
    $exponent = min(6, max(0, $attempts - 1));
    return min(3600, 60 * (2 ** $exponent));
  }

  /**
   * Marks a row failed or pending with backoff.
   */
  private function failRow(int|string $id, int $attempts, int $now, \Throwable $exception, bool $terminal): void {
    $this->database->update('postmark_integration_outbox')->fields([
      'status' => $terminal ? 'failed' : 'pending',
      'attempts' => $attempts,
      'available_at' => $now + ($terminal ? 0 : $this->backoff($attempts)),
      'last_error' => $this->safeError($exception),
    ])->condition('oid', $id)->execute();
    $this->logFailure($terminal);
  }

  /**
   * Rebuilds an event from stored JSON without logging it.
   */
  private function decode(string $payload): IntegrationEvent {
    try {
      $data = json_decode($payload, TRUE, 512, JSON_THROW_ON_ERROR);
    }
    catch (\JsonException $exception) {
      throw new \InvalidArgumentException('Stored integration payload is invalid.');
    }
    if (!is_array($data) || !is_array($data['source'] ?? NULL)) {
      throw new \InvalidArgumentException('Stored integration payload is invalid.');
    }
    if (($data['version'] ?? NULL) !== IntegrationEvent::VERSION) {
      throw new \InvalidArgumentException('Unsupported integration event version.');
    }
    return new IntegrationEvent(
      (string) $data['type'],
      (string) $data['eventKey'],
      (string) ($data['source']['serverId'] ?? ''),
      (string) ($data['source']['messageStream'] ?? ''),
      (int) $data['occurred'],
      (string) $data['timeBasis'],
      (string) $data['recipient'],
      isset($data['reason']) && is_string($data['reason']) ? $data['reason'] : NULL,
      array_key_exists('suppressed', $data) && is_bool($data['suppressed']) ? $data['suppressed'] : NULL,
    );
  }

  /**
   * Sanitizes a delivery error for storage and logs.
   */
  private function safeError(\Throwable $exception): string {
    $message = $exception->getMessage();
    $message = preg_replace('/[^\s]{1,64}@[\w.-]+/', '[redacted]', $message) ?? '[redacted]';
    return mb_substr($message, 0, 255);
  }

  /**
   * Logs delivery failure without payload content.
   */
  private function logFailure(bool $terminal): void {
    try {
      $this->loggerFactory->get('postmark_webhooks')->warning('Postmark Webhooks integration delivery @state.', [
        '@state' => $terminal ? 'failed' : 'retrying',
      ]);
    }
    catch (\Throwable $exception) {
      // Logging failure must not abort delivery accounting.
    }
  }

}
