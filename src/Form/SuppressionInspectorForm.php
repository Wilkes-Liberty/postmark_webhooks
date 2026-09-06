<?php

declare(strict_types=1);

namespace Drupal\postmark_webhooks\Form;

use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Url;
use Drupal\postmark_webhooks\Operator\SuppressionInspector;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Protected exact-address lookup with a redacted evidence timeline.
 */
final class SuppressionInspectorForm extends FormBase {

  /**
   * Constructs the inspector form.
   */
  public function __construct(protected SuppressionInspector $inspector) {}

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): self {
    return new self($container->get('postmark_webhooks.inspector'));
  }

  /**
   * {@inheritdoc}
   */
  public function getFormId(): string {
    return 'postmark_webhooks_inspector';
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state): array {
    $form['#cache']['max-age'] = 0;
    $form['#attributes']['class'][] = 'postmark-webhooks-operator';
    $form['#attached']['library'][] = 'postmark_webhooks/operator';
    $form['recipient'] = ['#type' => 'email', '#title' => $this->t('Recipient'), '#required' => TRUE];
    $form['actions']['#type'] = 'actions';
    $form['actions']['submit'] = ['#type' => 'submit', '#value' => $this->t('Inspect suppression')];
    if ($recipient = $form_state->get('recipient')) {
      $result = $this->inspector->inspect($recipient, $this->currentUser());
      $decision = $result['decision'];
      $form['decision'] = [
        '#type' => 'item',
        '#title' => $this->t('Effective policy for protected mail paths'),
        '#markup' => $decision['reason'] === 'disabled' ? $this->t('Suppression is disabled.') : ($decision['suppressed'] ? $this->t('Suppressed by policy.') : $this->t('No active suppression.')),
      ];
      $form['reason'] = ['#type' => 'item', '#title' => $this->t('Reason'), '#plain_text' => $decision['reason']];
      $form['expiry'] = [
        '#type' => 'item',
        '#title' => $this->t('Policy expiry'),
        '#plain_text' => $decision['expires'] ? gmdate('Y-m-d H:i:s \U\T\C', $decision['expires']) : (string) $this->t('No timed expiry'),
      ];
      $form['states'] = [
        '#type' => 'details',
        '#title' => $this->t('Durable evidence (up to 50 records)'),
        '#open' => TRUE,
      ];
      foreach ($result['states'] as $index => $state) {
        $form['states'][$index] = [
          '#type' => 'details',
          '#title' => $this->t('@reason — @time', [
            '@reason' => $state->reason,
            '@time' => gmdate('Y-m-d H:i:s \U\T\C', (int) $state->occurred),
          ]),
        ];
        $form['states'][$index]['source'] = [
          '#type' => 'item',
          '#title' => $this->t('Source and time basis'),
          '#plain_text' => $state->server_id . ' / ' . $state->message_stream . ' / ' . $state->time_basis,
        ];
        if ($this->currentUser()->hasPermission('recover postmark hard bounces')
          && in_array($state->reason, ['hard:HardBounce', 'hard:BadEmailAddress'], TRUE)
          && $state->server_id !== '' && $state->message_stream !== '') {
          $form['states'][$index]['recover'] = [
            '#type' => 'link',
            '#title' => $this->t('Review hard-bounce recovery for this source'),
            '#url' => Url::fromRoute('postmark_webhooks.recover', ['state_key' => $state->state_key]),
          ];
        }
      }
      $form['events'] = ['#type' => 'details', '#title' => $this->t('Recent history (up to 50 records)')];
      foreach ($result['events'] as $index => $event) {
        $form['events'][$index] = [
          '#type' => 'item',
          '#title' => gmdate('Y-m-d H:i:s \U\T\C', (int) $event->created),
          '#plain_text' => implode(' / ', [
            $event->event_type, $event->bounce_type, $event->server_id,
            $event->message_stream, $event->time_basis,
          ]),
        ];
      }
    }
    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state): void {
    $form_state->set('recipient', mb_strtolower(trim($form_state->getValue('recipient'))));
    $form_state->setRebuild();
  }

}
