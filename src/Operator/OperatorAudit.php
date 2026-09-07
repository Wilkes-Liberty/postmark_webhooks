<?php

declare(strict_types=1);

namespace Drupal\postmark_webhooks\Operator;

use Drupal\Core\Database\Connection;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\Core\Site\Settings;

/**
 * Records operator actions without storing a mailbox or free-text notes.
 *
 * When audit_chain is installed, a second copy is appended to that chain.
 * The local table remains the store of record. A chain write never undoes
 * the local row. The chain service is untyped so this class loads when
 * audit_chain is not installed.
 *
 * @internal
 */
final class OperatorAudit {

  /**
   * audit_chain channel name (bound into row hashes — do not rename).
   */
  public const CHAIN_CHANNEL = 'postmark_webhooks';

  /**
   * Constructs the audit store.
   *
   * @param \Drupal\Core\Database\Connection $database
   *   Database connection.
   * @param object|null $chain
   *   Optional audit_chain.logger service (has log($channel, $op, $meta)).
   * @param \Drupal\Core\Logger\LoggerChannelFactoryInterface $loggerFactory
   *   Logger factory for chain-write failures.
   */
  public function __construct(
    private readonly Connection $database,
    private readonly ?object $chain,
    private readonly LoggerChannelFactoryInterface $loggerFactory,
  ) {}

  /**
   * Appends a fixed action code and a pseudonymous subject reference.
   */
  public function record(string $action, string $recipient, AccountInterface $actor, int $now, string $target = ''): void {
    $subject = hash_hmac('sha256', mb_strtolower(trim($recipient)), Settings::getHashSalt());
    $uid = (int) $actor->id();
    $this->database->insert('postmark_operator_audit')->fields([
      'action' => $action,
      'target' => $target,
      'subject' => $subject,
      'uid' => $uid,
      'created' => $now,
    ])->execute();
    $this->dualWrite($action, $subject, $uid, $target);
  }

  /**
   * Best-effort copy onto audit_chain. Failures do not undo the local row.
   *
   * @param string $action
   *   Local action code.
   * @param string $subject
   *   HMAC of the recipient, never the mailbox.
   * @param int $uid
   *   Acting user id.
   * @param string $target
   *   Optional source or confirmation target.
   */
  private function dualWrite(string $action, string $subject, int $uid, string $target): void {
    if ($this->chain === NULL || !method_exists($this->chain, 'log')) {
      return;
    }
    $metadata = [
      'subject' => $subject,
      'uid' => (string) $uid,
    ];
    if ($target !== '') {
      $metadata['target'] = $target;
    }
    try {
      $this->chain->log(self::CHAIN_CHANNEL, $action, $metadata);
    }
    catch (\Throwable $e) {
      $this->loggerFactory->get('postmark_webhooks')->error(
        'audit_chain log failed for @op: @msg',
        [
          '@op' => $action,
          '@msg' => $e->getMessage(),
        ],
      );
    }
  }

}
