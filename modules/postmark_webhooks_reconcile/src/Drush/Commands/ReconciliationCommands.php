<?php

declare(strict_types=1);

namespace Drupal\postmark_webhooks_reconcile\Drush\Commands;

use Consolidation\OutputFormatters\StructuredData\UnstructuredData;
use Drupal\postmark_webhooks\Source\SourceContext;
use Drupal\postmark_webhooks_reconcile\Reconciliation;
use Drush\Attributes as CLI;
use Drush\Commands\DrushCommands;
use Psr\Container\ContainerInterface;

/**
 * Optional privileged-CLI reconciliation with a separate review/apply boundary.
 */
final class ReconciliationCommands extends DrushCommands {

  /**
   * Constructs the commands.
   */
  public function __construct(private readonly Reconciliation $reconciliation) {
    parent::__construct();
  }

  /**
   * Creates commands through Drush discovery.
   */
  public static function create(ContainerInterface $container): self {
    return new self($container->get('postmark_webhooks_reconcile.reconciliation'));
  }

  /**
   * Reviews one date-filtered provider dump without applying local changes.
   */
  #[CLI\Command(name: 'postmark-webhooks:reconcile-preview')]
  #[CLI\Argument(name: 'server', description: 'Configured Postmark server ID.')]
  #[CLI\Argument(name: 'stream', description: 'Postmark message stream ID.')]
  #[CLI\Argument(name: 'date', description: 'Inclusive UTC date in YYYY-MM-DD format.')]
  public function preview(string $server, string $stream, string $date, array $options = ['format' => 'json']): UnstructuredData {
    return new UnstructuredData($this->reconciliation->preview(new SourceContext($server, $stream), $date));
  }

  /**
   * Applies or resumes one page of a previously reviewed local import.
   */
  #[CLI\Command(name: 'postmark-webhooks:reconcile-apply')]
  #[CLI\Argument(name: 'job', description: 'Identifier returned by the reviewed preview.')]
  public function apply(string $job, array $options = ['format' => 'json']): UnstructuredData {
    return new UnstructuredData($this->reconciliation->apply($job));
  }

  /**
   * Shows progress without making any provider request.
   */
  #[CLI\Command(name: 'postmark-webhooks:reconcile-status')]
  #[CLI\Argument(name: 'job', description: 'Identifier returned by the reviewed preview.')]
  public function status(string $job, array $options = ['format' => 'json']): UnstructuredData {
    return new UnstructuredData($this->reconciliation->status($job));
  }

}
