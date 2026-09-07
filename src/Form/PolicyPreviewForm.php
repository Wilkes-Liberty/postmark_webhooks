<?php

declare(strict_types=1);

namespace Drupal\postmark_webhooks\Form;

use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\postmark_webhooks\Diagnostics\PolicyPreview;
use Drupal\postmark_webhooks\Source\SourceContext;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Previews policy without sending mail or changing suppression evidence.
 */
final class PolicyPreviewForm extends FormBase {

  use OperatorFormTrait;

  /**
   * Constructs the preview form.
   */
  public function __construct(protected PolicyPreview $preview) {}

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): self {
    return new self($container->get('postmark_webhooks.policy_preview'));
  }

  /**
   * {@inheritdoc}
   */
  public function getFormId(): string {
    return 'postmark_webhooks_policy_preview';
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state): array {
    $this->operatorShell(
      $form,
      'postmark-webhooks-preview-help',
      $this->t('This preview sends no mail and changes no suppression evidence. An allowed result does not guarantee provider acceptance or delivery.'),
    );
    $form['recipient'] = [
      '#type' => 'email',
      '#title' => $this->t('Recipient'),
      '#required' => TRUE,
    ];
    $form['mail_path'] = [
      '#type' => 'select',
      '#title' => $this->t('Mail path'),
      '#options' => [
        'core' => $this->t('Drupal core mail'),
        'mailer_plus' => $this->t('Mailer Plus'),
        'direct_symfony' => $this->t('Direct Symfony transport'),
      ],
    ];
    $form['server_id'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Sending server ID'),
      '#maxlength' => 20,
    ];
    $form['message_stream'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Sending message stream'),
      '#maxlength' => 255,
      '#description' => $this->t('Provide both source fields or leave both blank for conservative policy.'),
    ];
    $form['actions']['#type'] = 'actions';
    $form['actions']['submit'] = [
      '#type' => 'submit',
      '#value' => $this->t('Preview policy'),
    ];
    if ($result = $form_state->get('preview')) {
      $form['result'] = $this->operatorResult(
        'postmark-webhooks-preview-result',
        $this->t('Preview result'),
      );
      $form['result']['panel'] = [
        '#type' => 'details',
        '#title' => $this->t('Preview result'),
        '#open' => TRUE,
      ];
      if (!$result['enabled']) {
        $effective = $this->t('Suppression is disabled.');
      }
      elseif (!$result['covered']) {
        $effective = $this->t('This mail path is not protected by this module.');
      }
      elseif ($result['effective_block']) {
        $effective = $this->t('This module would block the message.');
      }
      else {
        $effective = $this->t('This module would allow the message.');
      }
      $form['result']['panel']['effective'] = [
        '#type' => 'item',
        '#title' => $this->t('Effective result'),
        '#markup' => $effective,
        '#wrapper_attributes' => ['role' => 'status'],
      ];
      $form['result']['panel']['reason'] = [
        '#type' => 'item',
        '#title' => $this->t('Policy reason'),
        '#plain_text' => $result['policy']['reason'],
      ];
      $form['result']['panel']['expires'] = [
        '#type' => 'item',
        '#title' => $this->t('Expiry'),
        '#plain_text' => $result['policy']['expires'] ? gmdate('Y-m-d H:i:s \U\T\C', $result['policy']['expires']) : (string) $this->t('No timed expiry'),
      ];
    }
    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function validateForm(array &$form, FormStateInterface $form_state): void {
    try {
      $server = trim($form_state->getValue('server_id'));
      $stream = trim($form_state->getValue('message_stream'));
      $source = ($server !== '' || $stream !== '') ? new SourceContext($server, $stream) : NULL;
    }
    catch (\InvalidArgumentException $exception) {
      $form_state->setErrorByName('server_id', $this->t('Provide a valid server ID and message stream, or leave both blank.'));
      $form_state->setErrorByName('message_stream', $this->t('Provide a valid server ID and message stream, or leave both blank.'));
      return;
    }
    try {
      $form_state->set('preview', $this->preview->preview(
        $form_state->getValue('recipient'),
        $form_state->getValue('mail_path'),
        $source,
      ));
    }
    catch (\InvalidArgumentException $exception) {
      $form_state->setErrorByName('recipient', $this->t('Enter one valid recipient.'));
    }
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state): void {
    $form_state->setRebuild();
  }

}
