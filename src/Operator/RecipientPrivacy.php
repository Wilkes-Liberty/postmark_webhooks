<?php

declare(strict_types=1);

namespace Drupal\postmark_webhooks\Operator;

use Drupal\Component\Datetime\TimeInterface;
use Drupal\Component\Utility\EmailValidatorInterface;
use Drupal\Core\Database\Connection;
use Drupal\Core\Session\AccountInterface;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;

/**
 * Audited recipient export and bounded history erasure preserving suppression.
 */
final class RecipientPrivacy {

  /**
   * Constructs the recipient privacy service.
   */
  public function __construct(
    private readonly Connection $database,
    private readonly OperatorAudit $audit,
    private readonly EmailValidatorInterface $emailValidator,
    private readonly TimeInterface $time,
  ) {}

  /**
   * Returns an event boundary for the exact recipient's erasure confirmation.
   */
  public function erasureBoundary(string $recipient, AccountInterface $actor): int {
    $recipient = $this->authorize($recipient, $actor, 'erase postmark recipient history');
    return $this->maximumEvent($recipient);
  }

  /**
   * Checks whether the confirmed snapshot still has history to erase.
   */
  public function hasHistory(string $recipient, int $maximum, AccountInterface $actor): bool {
    $recipient = $this->authorize($recipient, $actor, 'erase postmark recipient history');
    return (bool) $this->database->select('postmark_events', 'e')->fields('e', ['eid'])
      ->condition('recipient', $recipient)->condition('eid', $maximum, '<=')
      ->range(0, 1)->execute()->fetchField();
  }

  /**
   * Streams normalized records, omitting raw payloads and provider free text.
   */
  public function export(string $recipient, AccountInterface $actor): array {
    $recipient = $this->authorize($recipient, $actor, 'export postmark recipient data');
    $maximum = $this->maximumEvent($recipient);
    $this->audit->record('export_requested', $recipient, $actor, $this->time->getCurrentTime());
    return [
      'recipient' => $recipient,
      'event_snapshot_maximum' => $maximum,
      'suppression_retained_after_history_erasure' => TRUE,
      'events' => $this->eventRows($recipient, $maximum),
      'suppression' => $this->suppressionRows($recipient),
    ];
  }

  /**
   * Erases at most 250 history rows and audits that batch atomically.
   */
  public function eraseBatch(string $recipient, int $maximum, AccountInterface $actor): int {
    $recipient = $this->authorize($recipient, $actor, 'erase postmark recipient history');
    $ids = $this->database->select('postmark_events', 'e')->fields('e', ['eid'])
      ->condition('recipient', $recipient)->condition('eid', $maximum, '<=')
      ->orderBy('eid')->range(0, 250)->execute()->fetchCol();
    if (!$ids) {
      return 0;
    }
    $transaction = $this->database->startTransaction();
    try {
      $count = $this->database->delete('postmark_events')->condition('recipient', $recipient)
        ->condition('eid', $ids, 'IN')->execute();
      if ($count) {
        $this->audit->record('erase_history_batch', $recipient, $actor, $this->time->getCurrentTime());
      }
    }
    catch (\Throwable $exception) {
      $transaction->rollBack();
      throw $exception;
    }
    unset($transaction);
    return $count;
  }

  /**
   * Enforces a specific privacy permission and normalizes one mailbox.
   */
  private function authorize(string $recipient, AccountInterface $actor, string $permission): string {
    if (!$actor->hasPermission($permission)) {
      throw new AccessDeniedHttpException();
    }
    $recipient = mb_strtolower(trim($recipient));
    if (!$this->emailValidator->isValid($recipient)) {
      throw new \InvalidArgumentException('Enter one valid email address.');
    }
    return $recipient;
  }

  /**
   * Finds a stable upper boundary without counting or loading event history.
   */
  private function maximumEvent(string $recipient): int {
    $query = $this->database->select('postmark_events', 'e')->condition('recipient', $recipient);
    $query->addExpression('MAX(eid)');
    return (int) $query->execute()->fetchField();
  }

  /**
   * Iterates normalized event fields in bounded database pages.
   */
  private function eventRows(string $recipient, int $maximum): \Generator {
    $last = 0;
    do {
      $rows = $this->database->select('postmark_events', 'e')->fields('e', [
        'eid', 'created', 'occurred', 'time_basis', 'event_type', 'bounce_type',
        'server_id', 'message_stream', 'message_id', 'suppress_sending',
        'suppression_reason', 'origin',
      ])->condition('recipient', $recipient)->condition('eid', $last, '>')
        ->condition('eid', $maximum, '<=')->orderBy('eid')->range(0, 250)->execute()->fetchAll();
      foreach ($rows as $row) {
        $row = (array) $row;
        $last = (int) $row['eid'];
        yield $row;
      }
    } while (count($rows) === 250);
  }

  /**
   * Iterates minimal current suppression; concurrent intake may update it.
   */
  private function suppressionRows(string $recipient): \Generator {
    $last = '';
    do {
      $rows = $this->database->select('postmark_suppression', 's')->fields('s', [
        'state_key', 'server_id', 'message_stream', 'reason', 'occurred', 'time_basis', 'evidence',
      ])->condition('recipient', $recipient)->condition('state_key', $last, '>')
        ->orderBy('state_key')->range(0, 250)->execute()->fetchAll();
      foreach ($rows as $row) {
        $row = (array) $row;
        $last = $row['state_key'];
        yield $row;
      }
    } while (count($rows) === 250);
  }

}
