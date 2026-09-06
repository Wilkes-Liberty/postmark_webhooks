<?php

namespace Drupal\postmark_webhooks\Form;

use Drupal\Core\Form\ConfigFormBase;
use Drupal\Core\Form\FormStateInterface;

/**
 * Configures Postmark webhook suppression settings.
 */
class PostmarkWebhookSettingsForm extends ConfigFormBase {

  /**
   * {@inheritdoc}
   */
  public function getFormId(): string {
    return 'postmark_webhooks_settings_form';
  }

  /**
   * {@inheritdoc}
   */
  protected function getEditableConfigNames(): array {
    return ['postmark_webhooks.settings'];
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state): array {
    $config = $this->config('postmark_webhooks.settings');

    $webhook_url = $this->getRequest()->getSchemeAndHttpHost() . '/api/webhooks/postmark';
    $form['webhook_url'] = [
      '#type' => 'item',
      '#title' => $this->t('Webhook URL'),
      '#markup' => $this->t('@url', ['@url' => $webhook_url]),
      '#description' => $this->t('Put the secret in settings.php using @setting. Configure HTTP Basic Auth in the Postmark dashboard with username postmark and the secret as password. Use HTTPS and restrict the endpoint to Postmark webhook IP addresses.', ['@setting' => "\$settings['postmark_webhooks.webhook_secret']"]),
    ];

    $form['suppression'] = [
      '#type'  => 'details',
      '#title' => $this->t('Bounce & spam suppression'),
      '#open'  => TRUE,
    ];

    $form['suppression']['enabled'] = [
      '#type'          => 'checkbox',
      '#title'         => $this->t('Enable suppression'),
      '#description'   => $this->t('When checked, outbound email to bounced/spam addresses is blocked before sending.'),
      '#default_value' => $config->get('enabled') ?? TRUE,
    ];

    $form['suppression']['bounce_suppression_days'] = [
      '#type'          => 'number',
      '#title'         => $this->t('Soft bounce suppression window (days)'),
      '#description'   => $this->t('Suppress sends to soft-bounced addresses for this many days. Applies to: Transient, SoftBounce, DnsError, MailboxFull, MessageTooLarge.'),
      '#default_value' => $config->get('bounce_suppression_days') ?? 30,
      '#min'           => 0,
      '#max'           => 365,
    ];

    $form['suppression']['complaint_suppression_days'] = [
      '#type'          => 'number',
      '#title'         => $this->t('Spam complaint suppression window (days)'),
      '#description'   => $this->t('0 = permanent (never retry after a spam complaint). Hard bounces (HardBounce, BadEmailAddress, etc.) are always permanent regardless of this setting.'),
      '#default_value' => $config->get('complaint_suppression_days') ?? 0,
      '#min'           => 0,
      '#max'           => 3650,
    ];

    $form['suppression']['event_retention_days'] = [
      '#type'          => 'number',
      '#title'         => $this->t('Event log retention (days)'),
      '#description'   => $this->t('Cron deletes event history older than this. Durable suppression evidence is retained independently. 0 keeps history forever. The default is 90 days.'),
      '#default_value' => $config->get('event_retention_days') ?? 90,
      '#min'           => 0,
      '#max'           => 3650,
    ];

    return parent::buildForm($form, $form_state);
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state): void {
    $this->config('postmark_webhooks.settings')
      ->set('enabled', (bool) $form_state->getValue('enabled'))
      ->set('bounce_suppression_days', (int) $form_state->getValue('bounce_suppression_days'))
      ->set('complaint_suppression_days', (int) $form_state->getValue('complaint_suppression_days'))
      ->set('event_retention_days', (int) $form_state->getValue('event_retention_days'))
      ->save();

    parent::submitForm($form, $form_state);
  }

}
