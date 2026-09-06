<?php

declare(strict_types=1);

namespace Drupal\postmark_webhooks\Form;

use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\postmark_webhooks\Operator\RecipientPrivacy;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\StreamedJsonResponse;

/**
 * Downloads an audited, escaped JSON export without recipient query strings.
 */
final class RecipientExportForm extends FormBase {

  /**
   * Constructs the export form.
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
    return 'postmark_webhooks_recipient_export';
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state): array {
    $form['#cache']['max-age'] = 0;
    $form['#attributes']['class'][] = 'postmark-webhooks-operator';
    $form['#attached']['library'][] = 'postmark_webhooks/operator';
    $form['explanation'] = ['#markup' => $this->t('Export normalized recipient history and minimal suppression evidence as JSON. Provider free text and raw payloads are excluded. This request is audited. Protect the downloaded file as recipient data.')];
    $form['recipient'] = ['#type' => 'email', '#title' => $this->t('Recipient'), '#required' => TRUE];
    $form['actions']['#type'] = 'actions';
    $form['actions']['submit'] = ['#type' => 'submit', '#value' => $this->t('Download recipient export')];
    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state): void {
    $data = $this->privacy->export($form_state->getValue('recipient'), $this->currentUser());
    $response = new StreamedJsonResponse($data, 200, [
      'Content-Disposition' => 'attachment; filename="postmark-recipient-export.json"',
      'Cache-Control' => 'private, no-store',
      'X-Content-Type-Options' => 'nosniff',
    ], \JSON_HEX_TAG | \JSON_HEX_AMP | \JSON_HEX_APOS | \JSON_HEX_QUOT);
    $form_state->setResponse($response);
  }

}
