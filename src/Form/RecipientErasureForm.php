<?php

declare(strict_types=1);

namespace Drupal\postmark_webhooks\Form;

use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Url;
use Drupal\postmark_webhooks\Operator\RecipientPrivacy;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Confirms bounded history erasure while explicitly retaining suppression.
 */
final class RecipientErasureForm extends FormBase {

  use OperatorFormTrait;

  /**
   * Constructs the erasure form.
   */
  public function __construct(protected RecipientPrivacy $privacy) {}

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): self {
    return new self($container->get('postmark_webhooks.recipient_privacy'));
  }

  /**
   * {@inheritdoc}
   */
  public function getFormId(): string {
    return 'postmark_webhooks_recipient_erasure';
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state): array {
    $this->operatorShell(
      $form,
      'postmark-webhooks-erasure-help',
      $this->t('Erase this recipient’s stored event history only. Minimal suppression evidence, consent protections and audit records remain. This does not erase backups, downloaded exports, provider records or new events received after confirmation is prepared.'),
    );
    if ($recipient = $form_state->get('recipient')) {
      $form['subject'] = [
        '#type' => 'item',
        '#title' => $this->t('Recipient'),
        '#plain_text' => $recipient,
      ];
      $form['acknowledge'] = [
        '#type' => 'checkbox',
        '#title' => $this->t('I understand that suppression evidence and audit records remain.'),
        '#required' => TRUE,
      ];
      $label = $this->t('Confirm history erasure');
    }
    else {
      $form['recipient'] = [
        '#type' => 'email',
        '#title' => $this->t('Recipient'),
        '#required' => TRUE,
      ];
      $label = $this->t('Review history erasure');
    }
    $form['actions']['#type'] = 'actions';
    $form['actions']['submit'] = [
      '#type' => 'submit',
      '#value' => $label,
    ];
    if ($form_state->get('recipient')) {
      $form['actions']['submit']['#button_type'] = 'danger';
      $form['actions']['submit']['#attributes']['class'][] = 'button--danger';
      $form['actions']['cancel'] = [
        '#type' => 'link',
        '#title' => $this->t('Cancel'),
        '#url' => Url::fromRoute('postmark_webhooks.recipient_erase'),
        '#attributes' => ['class' => ['button']],
      ];
    }
    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state): void {
    if (!$form_state->get('recipient')) {
      $recipient = mb_strtolower(trim($form_state->getValue('recipient')));
      $form_state->set('recipient', $recipient);
      $form_state->set('maximum', $this->privacy->erasureBoundary($recipient, $this->currentUser()));
      $form_state->setRebuild();
      return;
    }
    batch_set([
      'title' => $this->t('Erasing recipient history'),
      'operations' => [
        [
          'postmark_webhooks_erase_history_batch',
          [$form_state->get('recipient'), $form_state->get('maximum'), (int) $this->currentUser()->id()],
        ],
      ],
      'finished' => 'postmark_webhooks_erase_history_finished',
    ]);
    $form_state->setRedirect('postmark_webhooks.recipient_erase');
  }

}
