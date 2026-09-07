<?php

namespace Drupal\postmark_webhooks\Suppression;

use Drupal\Core\Database\Connection;
use Drupal\Core\Database\IntegrityConstraintViolationException;

/**
 * Keeps bounded per-reason evidence independently of event-log retention.
 *
 * @internal
 */
final class SuppressionStore {

  /**
   * Constructs the store.
   */
  public function __construct(private readonly Connection $database) {}

  /**
   * Returns a policy reason for a stored event, or NULL for log-only events.
   */
  public static function reason(array $event): ?string {
    if ($event['event_type'] === 'SubscriptionChange') {
      if (!isset($event['suppress_sending'])) {
        return NULL;
      }
      if (!(bool) $event['suppress_sending']) {
        return 'release:hard';
      }
      return match ($event['suppression_reason'] ?? '') {
        'HardBounce' => 'hard:HardBounce',
        'SpamComplaint' => 'spam:SpamComplaint',
        'ManualSuppression' => 'consent:ManualSuppression',
        default => NULL,
      };
    }
    if (in_array($event['event_type'], ['SpamComplaint', 'SpamNotification'], TRUE)) {
      return 'spam:' . $event['event_type'];
    }
    if ($event['event_type'] !== 'Bounce') {
      return NULL;
    }
    if (in_array($event['bounce_type'], ['HardBounce', 'BadEmailAddress', 'ManuallyDeactivated', 'Unsubscribe'], TRUE)) {
      return 'hard:' . $event['bounce_type'];
    }
    if (in_array($event['bounce_type'], ['Transient', 'SoftBounce', 'DnsError', 'MailboxFull', 'MessageTooLarge'], TRUE)) {
      return 'soft:' . $event['bounce_type'];
    }
    return NULL;
  }

  /**
   * Records evidence without allowing delayed events to overwrite newer state.
   *
   * @return bool
   *   TRUE when a row was inserted or newer evidence replaced older state.
   */
  public function record(array $event): bool {
    $reason = self::reason($event);
    if ($reason === NULL) {
      return FALSE;
    }
    $recipient = mb_strtolower(trim($event['recipient']));
    $server = $event['server_id'] ?? '';
    $stream = $event['message_stream'] ?? '';
    $key = hash('sha256', json_encode([$recipient, $server, $stream, $reason], JSON_THROW_ON_ERROR));
    $row = [
      'state_key' => $key,
      'recipient' => $recipient,
      'server_id' => $server,
      'message_stream' => $stream,
      'reason' => $reason,
      'occurred' => ($event['time_basis'] ?? 'legacy') === 'legacy' ? $event['created'] : $event['occurred'],
      'time_basis' => $event['time_basis'] ?? 'legacy',
      'evidence' => $event['event_key'] ?? 'legacy:' . $event['eid'],
    ];
    $transaction = $this->database->startTransaction();
    try {
      $this->database->insert('postmark_suppression')->fields($row)->execute();
      unset($transaction);
      return TRUE;
    }
    catch (IntegrityConstraintViolationException $exception) {
      $transaction->rollBack();
      unset($transaction);
      if (!$this->database->select('postmark_suppression')->condition('state_key', $key)->countQuery()->execute()->fetchField()) {
        throw $exception;
      }
      $update = $this->database->update('postmark_suppression');
      $newer = $update->orConditionGroup()
        ->condition('occurred', $row['occurred'], '<')
        ->condition($update->andConditionGroup()->condition('occurred', $row['occurred'])->condition('evidence', $row['evidence'], '<'));
      $affected = $update->fields([
        'occurred' => $row['occurred'],
        'time_basis' => $row['time_basis'],
        'evidence' => $row['evidence'],
      ])->condition('state_key', $key)->condition($newer)->execute();
      return $affected > 0;
    }
    catch (\Throwable $exception) {
      $transaction->rollBack();
      throw $exception;
    }
  }

}
