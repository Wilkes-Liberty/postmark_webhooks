<?php

declare(strict_types=1);

namespace Drupal\postmark_webhooks\Operator;

use Drupal\Component\Datetime\TimeInterface;
use Drupal\Component\Utility\EmailValidatorInterface;
use Drupal\Core\Database\Connection;
use Drupal\Core\Session\AccountInterface;
use Drupal\postmark_webhooks\Source\SourceContext;
use Drupal\postmark_webhooks\Suppression\SuppressionPolicyInterface;
use Drupal\postmark_webhooks\Suppression\SuppressionStore;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;

/**
 * Exact-address inspection and narrowly scoped, audited local recovery.
 */
final class SuppressionInspector {

  /**
   * Constructs the inspector.
   */
  public function __construct(
    private readonly Connection $database,
    private readonly SuppressionPolicyInterface $policy,
    private readonly SuppressionStore $store,
    private readonly OperatorAudit $audit,
    private readonly EmailValidatorInterface $emailValidator,
    private readonly TimeInterface $time,
  ) {}

  /**
   * Returns a bounded, redacted exact-address view, never raw provider content.
   */
  public function inspect(string $recipient, AccountInterface $actor): array {
    $this->requireView($actor);
    $recipient = mb_strtolower(trim($recipient));
    if (!$this->emailValidator->isValid($recipient)) {
      throw new \InvalidArgumentException('Enter one valid email address.');
    }
    $states = $this->database->select('postmark_suppression', 's')->fields('s')
      ->condition('recipient', $recipient)->orderBy('occurred', 'DESC')->range(0, 50)->execute()->fetchAll();
    $events = $this->database->select('postmark_events', 'e')
      ->fields('e', ['created', 'occurred', 'time_basis', 'event_type', 'bounce_type', 'server_id', 'message_stream'])
      ->condition('recipient', $recipient)->orderBy('created', 'DESC')->range(0, 50)->execute()->fetchAll();
    return [
      'decision' => $this->policy->decide($recipient)->jsonSerialize(),
      'states' => $states,
      'events' => $events,
    ];
  }

  /**
   * Reads the selected evidence for confirmation, guarded at the service layer.
   */
  public function recoveryEvidence(string $key, AccountInterface $actor): object {
    $this->requireView($actor);
    if (!$actor->hasPermission('recover postmark hard bounces')) {
      throw new AccessDeniedHttpException();
    }
    $row = $this->database->select('postmark_suppression', 's')->fields('s')
      ->condition('state_key', $key)->forUpdate()->execute()->fetchObject();
    if (!$row || !in_array($row->reason, ['hard:HardBounce', 'hard:BadEmailAddress'], TRUE)) {
      throw new \InvalidArgumentException('This evidence is not eligible for recovery.');
    }
    new SourceContext($row->server_id, $row->message_stream);
    $release = $this->database->select('postmark_suppression', 's')->fields('s', ['occurred'])
      ->condition('recipient', $row->recipient)->condition('server_id', $row->server_id)
      ->condition('message_stream', $row->message_stream)->condition('reason', 'release:hard')
      ->execute()->fetchField();
    if (($release !== FALSE && (int) $release > (int) $row->occurred) || $this->time->getCurrentTime() <= (int) $row->occurred) {
      throw new \InvalidArgumentException('This evidence is already released or is too recent to recover safely.');
    }
    return $row;
  }

  /**
   * Records a source-specific release; complaints and consent remain intact.
   */
  public function recover(string $key, string $expectedEvidence, AccountInterface $actor): void {
    $transaction = $this->database->startTransaction();
    try {
      $row = $this->recoveryEvidence($key, $actor);
      if (!hash_equals($row->evidence, $expectedEvidence)) {
        throw new \InvalidArgumentException('Evidence changed. Inspect and confirm again.');
      }
      $now = $this->time->getCurrentTime();
      $this->store->record([
        'event_type' => 'SubscriptionChange',
        'suppress_sending' => 0,
        'recipient' => $row->recipient,
        'server_id' => $row->server_id,
        'message_stream' => $row->message_stream,
        'occurred' => $now,
        'created' => $now,
        'time_basis' => 'receipt',
        'event_key' => hash('sha256', random_bytes(32)),
      ]);
      $this->audit->record('recover_hard', $row->recipient, $actor, $now, $key);
    }
    catch (\Throwable $exception) {
      $transaction->rollBack();
      throw $exception;
    }
    unset($transaction);
  }

  /**
   * Enforces the view permission for every service entry point.
   */
  private function requireView(AccountInterface $actor): void {
    if (!$actor->hasPermission('view postmark suppression')) {
      throw new AccessDeniedHttpException();
    }
  }

}
