<?php

namespace Drupal\postmark_webhooks\Suppression;

use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Database\Connection;

/**
 * Evaluates durable evidence using the configured suppression windows.
 */
final class SuppressionPolicy implements SuppressionPolicyInterface {

  /**
   * Constructs the policy.
   */
  public function __construct(
    private readonly Connection $database,
    private readonly ConfigFactoryInterface $configFactory,
    private readonly TimeInterface $time,
  ) {}

  /**
   * {@inheritdoc}
   */
  public function decide(string $recipient): SuppressionDecision {
    $config = $this->configFactory->get('postmark_webhooks.settings');
    if (!$config->get('enabled')) {
      return new SuppressionDecision(FALSE, 'disabled');
    }
    $rows = $this->database->select('postmark_suppression', 'ps')
      ->fields('ps')
      ->condition('recipient', mb_strtolower(trim($recipient)))
      ->orderBy('occurred', 'DESC')
      ->orderBy('state_key')
      ->execute();
    $result = new SuppressionDecision(FALSE, 'no_active_suppression');
    $priority = 0;
    foreach ($rows as $row) {
      [$kind] = explode(':', $row->reason, 2);
      $rank = ['soft' => 1, 'hard' => 2, 'spam' => 3][$kind] ?? 0;
      $days = match ($kind) {
        'spam' => (int) $config->get('complaint_suppression_days'),
        'soft' => (int) $config->get('bounce_suppression_days'),
        default => 0,
      };
      if (!$rank || ($kind === 'soft' && $days <= 0)) {
        continue;
      }
      $expires = $days > 0 ? (int) $row->occurred + $days * 86400 : NULL;
      if (($expires !== NULL && $expires < $this->time->getCurrentTime()) || $rank <= $priority) {
        continue;
      }
      $priority = $rank;
      $result = new SuppressionDecision(TRUE, $row->reason, $expires, $row->evidence, (int) $row->occurred, $row->time_basis);
    }
    return $result;
  }

}
