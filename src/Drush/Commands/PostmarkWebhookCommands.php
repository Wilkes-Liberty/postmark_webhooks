<?php

declare(strict_types=1);

namespace Drupal\postmark_webhooks\Drush\Commands;

use Consolidation\OutputFormatters\StructuredData\UnstructuredData;
use Drupal\postmark_webhooks\Diagnostics\PolicyPreview;
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
  public function __construct(private readonly PolicyPreview $preview) {
    parent::__construct();
  }

  /**
   * Creates the commands with Drush 12+ service discovery.
   */
  public static function create(ContainerInterface $container): self {
    return new self($container->get('postmark_webhooks.policy_preview'));
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

}
