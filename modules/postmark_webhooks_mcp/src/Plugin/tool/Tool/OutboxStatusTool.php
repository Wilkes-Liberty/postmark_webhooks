<?php

declare(strict_types=1);

namespace Drupal\postmark_webhooks_mcp\Plugin\tool\Tool;

use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\postmark_webhooks\Integration\IntegrationOutbox;
use Drupal\tool\Attribute\Tool;
use Drupal\tool\Tool\ToolOperation;
use Drupal\tool\TypedData\InputDefinition;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Lists recent integration outbox rows.
 */
#[Tool(
  id: 'postmark_webhooks_outbox_status',
  label: new TranslatableMarkup('Postmark integration outbox status'),
  description: new TranslatableMarkup('List recent integration outbox rows, newest first: type, status, attempts, times and the scrubbed last error. Rows carry no recipient and no secret. Use it to find stuck or failed deliveries to subscribers.'),
  operation: ToolOperation::Read,
  input_definitions: [
    'limit' => new InputDefinition(
      data_type: 'integer',
      label: new TranslatableMarkup('Limit'),
      description: new TranslatableMarkup('Rows to return, 1 to 100.'),
      required: FALSE,
      default_value: 20,
      constraints: ['Range' => ['min' => 1, 'max' => 100]],
    ),
  ],
)]
final class OutboxStatusTool extends PostmarkToolBase {

  /**
   * Integration outbox.
   */
  protected IntegrationOutbox $outbox;

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition) {
    $instance = parent::create($container, $configuration, $plugin_id, $plugin_definition);
    $instance->outbox = $container->get('postmark_webhooks.integration_outbox');
    return $instance;
  }

  /**
   * {@inheritdoc}
   */
  protected function inputNames(): array {
    return ['limit'];
  }

  /**
   * {@inheritdoc}
   */
  protected function read(array $values): array {
    $limit = (int) ($values['limit'] ?? 20);
    if ($limit < 1 || $limit > 100) {
      throw new \InvalidArgumentException('Limit out of range.');
    }
    return ['rows' => $this->outbox->inspect($limit)];
  }

}
