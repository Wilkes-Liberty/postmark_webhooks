<?php

declare(strict_types=1);

namespace Drupal\postmark_webhooks_mcp\Plugin\tool\Tool;

use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\postmark_webhooks\Diagnostics\HealthEvaluator;
use Drupal\tool\Attribute\Tool;
use Drupal\tool\Tool\ToolOperation;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Reports webhook intake health without mailboxes or secrets.
 */
#[Tool(
  id: 'postmark_webhooks_health',
  label: new TranslatableMarkup('Postmark webhook health'),
  description: new TranslatableMarkup('Report webhook intake health: secret and rotation state, intake silence, retention backlog and source checks. Returns no mailboxes and no secrets. Endpoint reachability is never proven from inside the site.'),
  operation: ToolOperation::Read,
  input_definitions: [],
)]
final class HealthTool extends PostmarkToolBase {

  /**
   * Health evaluator.
   */
  protected HealthEvaluator $health;

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition) {
    $instance = parent::create($container, $configuration, $plugin_id, $plugin_definition);
    $instance->health = $container->get('postmark_webhooks.health');
    return $instance;
  }

  /**
   * {@inheritdoc}
   */
  protected function read(array $values): array {
    return ['health' => $this->health->evaluate()];
  }

}
