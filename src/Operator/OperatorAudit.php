<?php

declare(strict_types=1);

namespace Drupal\postmark_webhooks\Operator;

use Drupal\Core\Database\Connection;
use Drupal\Core\Session\AccountInterface;
use Drupal\Core\Site\Settings;

/**
 * Records operator actions without storing a mailbox or free-text notes.
 */
final class OperatorAudit {

  /**
   * Constructs the audit store.
   */
  public function __construct(private readonly Connection $database) {}

  /**
   * Appends a fixed action code and a pseudonymous subject reference.
   */
  public function record(string $action, string $recipient, AccountInterface $actor, int $now, string $target = ''): void {
    $this->database->insert('postmark_operator_audit')->fields([
      'action' => $action,
      'target' => $target,
      'subject' => hash_hmac('sha256', mb_strtolower(trim($recipient)), Settings::getHashSalt()),
      'uid' => (int) $actor->id(),
      'created' => $now,
    ])->execute();
  }

}
