<?php

namespace Drupal\postmark_webhooks\Commands;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Database\Connection;
use Drush\Attributes as CLI;
use Drush\Commands\DrushCommands;

/**
 * Drush commands for inspecting Postmark suppression status.
 */
class PostmarkWebhookCommands extends DrushCommands {

  /**
   * Constructs the status commands.
   */
  public function __construct(
    protected ConfigFactoryInterface $configFactory,
    protected Connection $database,
  ) {
    parent::__construct();
  }

  /**
   * Reports whether an address is suppressed and why.
   */
  #[CLI\Command(name: 'postmark-webhooks:status', aliases: ['pm-wh:status'])]
  #[CLI\Argument(name: 'email', description: 'Email address to check.')]
  #[CLI\Usage(name: 'drush postmark-webhooks:status user@example.com', description: 'Check if an address is suppressed and why.')]
  public function status(string $email): void {
    $config = $this->configFactory->get('postmark_webhooks.settings');

    if (!$config->get('enabled')) {
      $this->io()->note('Suppression is currently disabled globally (postmark_webhooks.settings.enabled = false).');
    }

    $reason = _postmark_webhooks_suppression_reason($email, $config);

    if ($reason) {
      $this->io()->error("SUPPRESSED: {$email} — {$reason}");
    }
    else {
      $this->io()->success("OK: {$email} is not suppressed.");
    }

    // Show recent events regardless of suppression state.
    $rows = $this->database->select('postmark_events', 'pe')
      ->fields('pe', ['eid', 'created', 'occurred', 'time_basis', 'event_type', 'bounce_type', 'description'])
      ->where('LOWER(recipient) = LOWER(:email)', [':email' => $email])
      ->orderBy('created', 'DESC')
      ->range(0, 10)
      ->execute()
      ->fetchAll();

    if (empty($rows)) {
      $this->io()->text('No Postmark events on record for this address.');
      return;
    }

    $table = [];
    foreach ($rows as $row) {
      $table[] = [
        date('Y-m-d H:i', $row->created),
        date('Y-m-d H:i', $row->time_basis === 'legacy' ? $row->created : $row->occurred),
        $row->time_basis,
        $row->event_type,
        $row->bounce_type ?: '—',
        mb_substr($row->description, 0, 60),
      ];
    }
    $this->io()->table(['Received', 'Occurred', 'Time basis', 'Type', 'Bounce Type', 'Description'], $table);
  }

}
