<?php

declare(strict_types=1);

namespace Drupal\postmark_webhooks\Drush\Commands;

use Consolidation\OutputFormatters\StructuredData\UnstructuredData;
use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\postmark_webhooks\Diagnostics\HealthEvaluator;
use Drupal\postmark_webhooks\Diagnostics\PolicyPreview;
use Drupal\postmark_webhooks\Integration\IntegrationOutbox;
use Drupal\postmark_webhooks\Retention\EventRetention;
use Drupal\postmark_webhooks\Source\SourceContext;
use Drush\Attributes as CLI;
use Drush\Commands\DrushCommands;
use Psr\Container\ContainerInterface;

/**
 * Read-only policy previews and privacy-safe intake diagnostics.
 */
final class PostmarkWebhookCommands extends DrushCommands {

  /**
   * Constructs the commands.
   */
  public function __construct(
    private readonly PolicyPreview $preview,
    private readonly HealthEvaluator $health,
    private readonly IntegrationOutbox $outbox,
    private readonly EventRetention $retention,
    private readonly ConfigFactoryInterface $configFactory,
    private readonly TimeInterface $time,
  ) {
    parent::__construct();
  }

  /**
   * Creates the commands with Drush 12+ service discovery.
   */
  public static function create(ContainerInterface $container): self {
    return new self(
      $container->get('postmark_webhooks.policy_preview'),
      $container->get('postmark_webhooks.health'),
      $container->get('postmark_webhooks.integration_outbox'),
      $container->get('postmark_webhooks.event_retention'),
      $container->get('config.factory'),
      $container->get('datetime.time'),
    );
  }

  /**
   * Previews this module's effective block without sending mail.
   */
  #[CLI\Command(name: 'postmark-webhooks:status', aliases: ['pm-wh:status'])]
  #[CLI\Argument(name: 'email', description: 'One email address to check.')]
  #[CLI\Option(name: 'mail-path', description: 'core, mailer_plus, or direct_symfony.')]
  #[CLI\Option(name: 'server-id', description: 'Trusted sending server ID; requires message-stream.')]
  #[CLI\Option(name: 'message-stream', description: 'Trusted sending stream; requires server-id.')]
  public function status(
    string $email,
    array $options = [
      'format' => 'yaml',
      'mail-path' => 'core',
      'server-id' => NULL,
      'message-stream' => NULL,
    ],
  ): UnstructuredData {
    $source = NULL;
    if ($options['server-id'] !== NULL || $options['message-stream'] !== NULL) {
      if ($options['server-id'] === NULL || $options['message-stream'] === NULL) {
        throw new \InvalidArgumentException('Specify both server-id and message-stream.');
      }
      $source = new SourceContext((string) $options['server-id'], (string) $options['message-stream']);
    }
    return new UnstructuredData($this->preview->preview($email, $options['mail-path'], $source));
  }

  /**
   * Reports configuration readiness and aggregate authenticated intake totals.
   */
  #[CLI\Command(name: 'postmark-webhooks:diagnostics', aliases: ['pm-wh:diagnostics'])]
  public function diagnostics(array $options = ['format' => 'yaml']): UnstructuredData {
    return new UnstructuredData($this->preview->diagnostics());
  }

  /**
   * Reports webhook health without recipient or secret labels.
   */
  #[CLI\Command(name: 'postmark-webhooks:health', aliases: ['pm-wh:health'])]
  public function health(array $options = ['format' => 'yaml']): UnstructuredData {
    return new UnstructuredData($this->health->evaluate());
  }

  /**
   * Lists integration outbox rows without recipient or secret labels.
   */
  #[CLI\Command(name: 'postmark-webhooks:outbox', aliases: ['pm-wh:outbox'])]
  public function outbox(array $options = ['format' => 'json']): UnstructuredData {
    return new UnstructuredData([
      'rows' => $this->outbox->inspect(),
    ]);
  }

  /**
   * Delivers one bounded outbox page without sending mail.
   */
  #[CLI\Command(name: 'postmark-webhooks:outbox-dispatch', aliases: ['pm-wh:outbox-dispatch'])]
  public function outboxDispatch(array $options = ['format' => 'json']): UnstructuredData {
    return new UnstructuredData($this->outbox->dispatch());
  }

  /**
   * Drains expired event history in bounded batches without sending mail.
   */
  #[CLI\Command(name: 'postmark-webhooks:retention-drain', aliases: ['pm-wh:retention-drain'])]
  #[CLI\Option(name: 'batches', description: 'Batches of 250 rows. Defaults to the configured cron count.')]
  #[CLI\Option(name: 'seconds', description: 'Wall-time budget. Defaults to the configured cron budget.')]
  public function retentionDrain(
    array $options = [
      'format' => 'json',
      'batches' => NULL,
      'seconds' => NULL,
    ],
  ): UnstructuredData {
    $config = $this->configFactory->get('postmark_webhooks.settings');
    $days = (int) ($config->get('event_retention_days') ?? 90);
    if ($days <= 0) {
      return new UnstructuredData([
        'deleted' => 0,
        'batches' => 0,
        'stopped' => 'disabled',
        'oldest_expired' => NULL,
      ]);
    }
    $batches = $options['batches'] === NULL
      ? (int) ($config->get('event_retention_batches') ?? 1)
      : (int) $options['batches'];
    $budget = $options['seconds'] === NULL
      ? (int) ($config->get('event_retention_time_budget_seconds') ?? 0)
      : (int) $options['seconds'];
    $cutoff = $this->time->getRequestTime() - ($days * 86400);
    return new UnstructuredData($this->retention->drain($cutoff, $batches, $budget));
  }

}
