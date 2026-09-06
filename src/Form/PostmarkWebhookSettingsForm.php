<?php

namespace Drupal\postmark_webhooks\Form;

use Drupal\Core\Form\ConfigFormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Url;
use Drupal\postmark_webhooks\Diagnostics\PolicyPreview;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Configures Postmark webhook suppression settings.
 */
class PostmarkWebhookSettingsForm extends ConfigFormBase {

  /**
   * Read-only effective diagnostics.
   */
  protected PolicyPreview $preview;

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    $instance = parent::create($container);
    $instance->preview = $container->get('postmark_webhooks.policy_preview');
    return $instance;
  }

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
    $diagnostics = $this->preview->diagnostics();
    $form['#cache']['max-age'] = 0;
    $form['#attributes']['class'][] = 'postmark-webhooks-operator';
    $form['#attached']['library'][] = 'postmark_webhooks/operator';
    $form['diagnostics'] = ['#type' => 'details', '#title' => $this->t('Effective status'), '#open' => TRUE];
    $form['diagnostics']['secret'] = [
      '#type' => 'item',
      '#title' => $this->t('Webhook credential'),
      '#markup' => $diagnostics['secret_configured'] ? $this->t('Configured') : $this->t('Missing or invalid; intake is unavailable'),
    ];
    $form['diagnostics']['coverage'] = [
      '#type' => 'item',
      '#title' => $this->t('Mail coverage'),
      '#markup' => $diagnostics['coverage']['mailer_plus'] ? $this->t('Drupal core mail and Mailer Plus are protected when suppression is enabled. Direct Symfony transports are not covered.') : $this->t('Drupal core mail is protected when suppression is enabled. Native Mailer Plus requires the optional Postmark Webhooks Mailer adapter. Direct Symfony transports are not covered.'),
    ];
    $accepted = $diagnostics['intake']['accepted'];
    $form['diagnostics']['intake'] = [
      '#type' => 'item',
      '#title' => $this->t('Intake since diagnostics installation'),
      '#markup' => $this->t('Accepted: @accepted. Duplicate retries: @duplicates. Authenticated rejections: @rejected. Last accepted: @last.', [
        '@accepted' => $accepted['total'],
        '@duplicates' => $diagnostics['intake']['duplicate']['total'],
        '@rejected' => $diagnostics['intake']['rejected']['total'],
        '@last' => $accepted['last_seen'] ? gmdate('Y-m-d H:i:s \U\T\C', $accepted['last_seen']) : $this->t('None recorded'),
      ]),
    ];
    $form['diagnostics']['preview'] = [
      '#type' => 'link',
      '#title' => $this->t('Preview policy without sending mail'),
      '#url' => Url::fromRoute('postmark_webhooks.preview'),
    ];

    $webhook_url = Url::fromRoute('postmark_webhooks.receive', [], ['absolute' => TRUE])->toString();
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
