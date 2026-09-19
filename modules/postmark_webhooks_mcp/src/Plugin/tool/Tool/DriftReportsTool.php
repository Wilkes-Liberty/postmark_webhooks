<?php

declare(strict_types=1);

namespace Drupal\postmark_webhooks_mcp\Plugin\tool\Tool;

use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\tool\Attribute\Tool;
use Drupal\tool\Tool\ToolOperation;
use Drupal\tool\TypedData\InputDefinition;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Lists recent drift reports from the optional reconciliation submodule.
 */
#[Tool(
  id: 'postmark_webhooks_drift_reports',
  label: new TranslatableMarkup('Postmark drift reports'),
  description: new TranslatableMarkup('List recent scheduled drift reports that compare provider suppression with local suppression, newest first. Reports carry counts only, no recipients and no secrets. Available only when the reconciliation submodule is installed.'),
  operation: ToolOperation::Read,
  input_definitions: [
    'limit' => new InputDefinition(
      data_type: 'integer',
      label: new TranslatableMarkup('Limit'),
      description: new TranslatableMarkup('Reports to return, 1 to 100.'),
      required: FALSE,
      default_value: 20,
      constraints: ['Range' => ['min' => 1, 'max' => 100]],
    ),
  ],
)]
final class DriftReportsTool extends PostmarkToolBase {

  /**
   * Service ID of the scheduled reconciliation reader.
   */
  private const SERVICE = 'postmark_webhooks_reconcile.schedule';

  /**
   * Scheduled reconciliation reader, or NULL when the submodule is absent.
   *
   * @var object|null
   */
  protected ?object $reconciliation = NULL;

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition) {
    $instance = parent::create($container, $configuration, $plugin_id, $plugin_definition);
    $instance->reconciliation = $container->has(self::SERVICE) ? $container->get(self::SERVICE) : NULL;
    return $instance;
  }

  /**
   * {@inheritdoc}
   */
  protected function available(): bool {
    return $this->reconciliation !== NULL;
  }

  /**
   * {@inheritdoc}
   */
  protected function read(array $values): array {
    $limit = (int) ($values['limit'] ?? 20);
    if ($limit < 1 || $limit > 100 || $this->reconciliation === NULL) {
      throw new \InvalidArgumentException('Unavailable.');
    }
    return ['reports' => $this->reconciliation->reports($limit)];
  }

}
