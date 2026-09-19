<?php

declare(strict_types=1);

namespace Drupal\postmark_webhooks_mcp\Plugin\tool\Tool;

use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\postmark_webhooks\Diagnostics\PolicyPreview;
use Drupal\postmark_webhooks\Operator\OperatorAudit;
use Drupal\postmark_webhooks\Source\SourceContext;
use Drupal\tool\Attribute\Tool;
use Drupal\tool\Tool\ToolOperation;
use Drupal\tool\TypedData\InputDefinition;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Predicts whether this module would block mail to one address.
 *
 * The address is input only. It is never returned, and the audit row stores a
 * keyed hash of it, not the mailbox.
 */
#[Tool(
  id: 'postmark_webhooks_delivery_preview',
  label: new TranslatableMarkup('Postmark delivery preview'),
  description: new TranslatableMarkup('For one email address and mail path, report whether this module would block the message and the reason code. Predicts only the block this module applies, never provider acceptance or inbox delivery. The address is not returned or logged; the lookup is audited under a keyed hash.'),
  operation: ToolOperation::Read,
  input_definitions: [
    'email' => new InputDefinition(
      data_type: 'email',
      label: new TranslatableMarkup('Email address'),
      description: new TranslatableMarkup('One mailbox. Not a To, Cc or Bcc list.'),
      required: TRUE,
      constraints: ['Length' => ['max' => 254]],
    ),
    'mail_path' => new InputDefinition(
      data_type: 'string',
      label: new TranslatableMarkup('Mail path'),
      description: new TranslatableMarkup('How the message would be sent.'),
      required: FALSE,
      default_value: 'core',
      constraints: ['Choice' => ['choices' => ['core', 'mailer_plus', 'direct_symfony']]],
    ),
    'server_id' => new InputDefinition(
      data_type: 'string',
      label: new TranslatableMarkup('Sending server ID'),
      description: new TranslatableMarkup('Numeric Postmark server ID. Provide it together with message_stream, or omit both to apply all evidence.'),
      required: FALSE,
      constraints: ['Regex' => ['pattern' => '/^[0-9]{1,20}$/D']],
    ),
    'message_stream' => new InputDefinition(
      data_type: 'string',
      label: new TranslatableMarkup('Message stream'),
      description: new TranslatableMarkup('Postmark message stream. Provide it together with server_id.'),
      required: FALSE,
      constraints: ['Length' => ['min' => 1, 'max' => 255]],
    ),
  ],
)]
final class DeliveryPreviewTool extends PostmarkToolBase {

  /**
   * Policy preview service.
   */
  protected PolicyPreview $preview;

  /**
   * Operator audit store.
   */
  protected OperatorAudit $audit;

  /**
   * Clock.
   */
  protected TimeInterface $time;

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition) {
    $instance = parent::create($container, $configuration, $plugin_id, $plugin_definition);
    $instance->preview = $container->get('postmark_webhooks.policy_preview');
    $instance->audit = $container->get('postmark_webhooks.operator_audit');
    $instance->time = $container->get('datetime.time');
    return $instance;
  }

  /**
   * {@inheritdoc}
   */
  protected function inputNames(): array {
    return ['email', 'mail_path', 'server_id', 'message_stream'];
  }

  /**
   * {@inheritdoc}
   */
  protected function read(array $values): array {
    $email = (string) ($values['email'] ?? '');
    $server = trim((string) ($values['server_id'] ?? ''));
    $stream = trim((string) ($values['message_stream'] ?? ''));
    if (($server === '') !== ($stream === '')) {
      throw new \InvalidArgumentException('Partial source.');
    }
    $source = $server === '' ? NULL : new SourceContext($server, $stream);
    $result = $this->preview->preview($email, (string) ($values['mail_path'] ?? 'core'), $source);
    // Audit after validation, so a refused input writes no subject hash.
    $this->audit->record('mcp_delivery_preview', $email, $this->currentUser, $this->time->getCurrentTime());
    return ['preview' => $result];
  }

}
