<?php

declare(strict_types=1);

namespace Drupal\postmark_webhooks_mcp\Plugin\tool\Tool;

use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\postmark_webhooks\Operator\EventTimeline;
use Drupal\tool\Attribute\Tool;
use Drupal\tool\Tool\ToolOperation;
use Drupal\tool\TypedData\InputDefinition;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Returns retained events for one MessageID with recipients removed.
 */
#[Tool(
  id: 'postmark_webhooks_message_timeline',
  label: new TranslatableMarkup('Postmark message timeline'),
  description: new TranslatableMarkup('Return retained webhook events for one exact Postmark MessageID: event type, bounce type, source and times, 25 per page. Recipients are replaced by a per-message label and a suppressed flag. Delivery is provider evidence, not proof of inbox placement.'),
  operation: ToolOperation::Read,
  input_definitions: [
    'message_id' => new InputDefinition(
      data_type: 'string',
      label: new TranslatableMarkup('MessageID'),
      description: new TranslatableMarkup('Exact Postmark MessageID.'),
      required: TRUE,
      constraints: ['Length' => ['min' => 1, 'max' => 255]],
    ),
    'event_type' => new InputDefinition(
      data_type: 'string',
      label: new TranslatableMarkup('Event type'),
      description: new TranslatableMarkup('Limit to one record type.'),
      required: FALSE,
      constraints: ['Choice' => ['choices' => EventTimeline::EVENT_TYPES]],
    ),
    'page' => new InputDefinition(
      data_type: 'integer',
      label: new TranslatableMarkup('Page'),
      description: new TranslatableMarkup('Zero-based page index.'),
      required: FALSE,
      default_value: 0,
      constraints: ['Range' => ['min' => 0, 'max' => 1000]],
    ),
  ],
)]
final class MessageTimelineTool extends PostmarkToolBase {

  /**
   * Timeline reader.
   */
  protected EventTimeline $timeline;

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition) {
    $instance = parent::create($container, $configuration, $plugin_id, $plugin_definition);
    $instance->timeline = $container->get('postmark_webhooks.event_timeline');
    return $instance;
  }

  /**
   * {@inheritdoc}
   */
  protected function inputNames(): array {
    return ['message_id', 'event_type', 'page'];
  }

  /**
   * {@inheritdoc}
   */
  protected function extraPermissions(): array {
    return ['view postmark suppression'];
  }

  /**
   * {@inheritdoc}
   */
  protected function read(array $values): array {
    $filters = array_filter(['event_type' => (string) ($values['event_type'] ?? '')]);
    $found = $this->timeline->lookup(
      (string) ($values['message_id'] ?? ''),
      $filters,
      $this->currentUser,
      (int) ($values['page'] ?? 0),
    );
    // The reader keys suppression by mailbox. Swap each mailbox for a label
    // that is stable within this response and carries no address data.
    $labels = [];
    $events = [];
    foreach ($found['events'] as $row) {
      $mailbox = (string) $row->recipient;
      $labels[$mailbox] ??= 'recipient_' . (count($labels) + 1);
      $events[] = [
        'created' => (int) $row->created,
        'occurred' => $row->occurred === NULL ? NULL : (int) $row->occurred,
        'time_basis' => (string) $row->time_basis,
        'event_type' => (string) $row->event_type,
        'bounce_type' => (string) $row->bounce_type,
        'server_id' => (string) $row->server_id,
        'message_stream' => (string) $row->message_stream,
        'recipient' => $labels[$mailbox],
        'suppressed_now' => (bool) ($found['suppressed'][$mailbox] ?? FALSE),
      ];
    }
    return [
      'events' => $events,
      'total' => (int) $found['total'],
      'page' => (int) $found['page'],
      'pages' => (int) $found['pages'],
    ];
  }

}
