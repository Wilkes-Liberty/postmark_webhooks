<?php

namespace Drupal\postmark_webhooks\Suppression;

use Drupal\postmark_webhooks\Source\SourceContext;
use Drupal\postmark_webhooks\Source\SourcePolicy;

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
  public function decide(string $recipient, ?SourceContext $source = NULL): SuppressionDecision {
    $config = $this->configFactory->get('postmark_webhooks.settings');
    if (!$config->get('enabled')) {
      return new SuppressionDecision(FALSE, 'disabled');
    }
    try {
      $mappings = SourcePolicy::validate($config->get('source_policies'));
    }
    catch (\InvalidArgumentException $exception) {
      return new SuppressionDecision(TRUE, 'invalid_source_policy');
    }
    $rows = $this->database->select('postmark_suppression', 'ps')
      ->fields('ps')
      ->condition('recipient', mb_strtolower(trim($recipient)))
      ->orderBy('occurred', 'DESC')
      ->orderBy('state_key')
      ->execute()->fetchAll();
    $releases = [];
    foreach ($rows as $row) {
      if ($row->reason === 'release:hard' && $row->server_id !== '' && $row->message_stream !== '') {
        $key = json_encode([$row->server_id, $row->message_stream], JSON_THROW_ON_ERROR);
        $releases[$key] = (int) $row->occurred;
      }
    }
    $result = new SuppressionDecision(FALSE, 'no_active_suppression');
    $priority = 0;
    foreach ($rows as $row) {
      if (!SourcePolicy::applies($row, $source, $mappings)) {
        continue;
      }
      $source_key = json_encode([$row->server_id, $row->message_stream], JSON_THROW_ON_ERROR);
      if (in_array($row->reason, ['hard:HardBounce', 'hard:BadEmailAddress'], TRUE)
        && ($releases[$source_key] ?? -1) > (int) $row->occurred) {
        continue;
      }
      [$kind] = explode(':', $row->reason, 2);
      $rank = ['soft' => 1, 'hard' => 2, 'spam' => 3, 'consent' => 4][$kind] ?? 0;
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
