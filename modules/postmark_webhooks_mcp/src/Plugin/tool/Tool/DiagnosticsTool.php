<?php

declare(strict_types=1);

namespace Drupal\postmark_webhooks_mcp\Plugin\tool\Tool;

use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\postmark_webhooks\Diagnostics\PolicyPreview;
use Drupal\tool\Attribute\Tool;
use Drupal\tool\Tool\ToolOperation;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Reports configuration readiness, mail-path coverage and intake counters.
 */
#[Tool(
  id: 'postmark_webhooks_diagnostics',
  label: new TranslatableMarkup('Postmark webhook diagnostics'),
  description: new TranslatableMarkup('Report whether suppression is enabled, which mail paths it covers, source profile status, accepted, duplicate and rejected intake totals, and a health summary. Returns no mailboxes and no secrets.'),
  operation: ToolOperation::Read,
  input_definitions: [],
)]
final class DiagnosticsTool extends PostmarkToolBase {

  /**
   * Policy preview and diagnostics service.
   */
  protected PolicyPreview $preview;

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition) {
    $instance = parent::create($container, $configuration, $plugin_id, $plugin_definition);
    $instance->preview = $container->get('postmark_webhooks.policy_preview');
    return $instance;
  }

  /**
   * {@inheritdoc}
   */
  protected function inputNames(): array {
    return [];
  }

  /**
   * {@inheritdoc}
   */
  protected function read(array $values): array {
    return ['diagnostics' => $this->preview->diagnostics()];
  }

}
