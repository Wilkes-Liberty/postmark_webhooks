<?php

declare(strict_types=1);

namespace Drupal\postmark_webhooks\Form;

use Drupal\Core\Form\ConfirmFormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Url;
use Drupal\postmark_webhooks\Operator\SuppressionInspector;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;

/**
 * Confirms a single source's hard-bounce release with Drupal CSRF protection.
 */
final class HardBounceRecoveryForm extends ConfirmFormBase {

  use OperatorFormTrait;

  /**
   * Constructs the recovery form.
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
    return 'postmark_webhooks_hard_bounce_recovery';
  }

  /**
   * {@inheritdoc}
   */
  public function getQuestion() {
    return $this->t('Release recoverable hard bounces for this source?');
  }

  /**
   * {@inheritdoc}
   */
  public function getDescription() {
    return $this->t('Confirm that the mailbox problem has been corrected. This releases local HardBounce and BadEmailAddress evidence for this recipient and source only. Complaints, manual suppression, unsubscribe and other sources remain protected. No mail is sent and no provider setting is changed. The action is audited.');
  }

  /**
   * {@inheritdoc}
   */
  public function getCancelUrl() {
    return Url::fromRoute('postmark_webhooks.inspector');
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state, string $state_key = ''): array {
    try {
      $row = $this->inspector->recoveryEvidence($state_key, $this->currentUser());
    }
    catch (\InvalidArgumentException $exception) {
      throw new BadRequestHttpException($exception->getMessage());
    }
    $form['subject'] = [
      '#type' => 'item',
      '#title' => $this->t('Recipient and source'),
      '#plain_text' => $row->recipient . ' / ' . $row->server_id . ' / ' . $row->message_stream,
    ];
    // The submitted snapshot is compared again after confirmation. A new event
    // must not silently substitute different evidence in a pending form.
    $form['state_key'] = ['#type' => 'hidden', '#value' => $state_key];
    $form['evidence'] = ['#type' => 'hidden', '#default_value' => $row->evidence];
    $form = parent::buildForm($form, $form_state);
    $this->operatorShell($form, 'postmark-webhooks-recovery-help');
    $form['#attributes']['aria-describedby'] = 'postmark-webhooks-recovery-help';
    $form['description'] = [
      '#type' => 'container',
      '#attributes' => [
        'id' => 'postmark-webhooks-recovery-help',
        'class' => ['postmark-webhooks-operator__help'],
        'role' => 'note',
      ],
      'text' => ['#markup' => $this->getDescription()],
    ];
    $form['actions']['submit']['#button_type'] = 'danger';
    $form['actions']['submit']['#attributes']['class'][] = 'button--danger';
    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state): void {
    try {
      $this->inspector->recover(
        $form_state->getValue('state_key'),
        $form_state->getValue('evidence'),
        $this->currentUser(),
      );
      $this->messenger()->addStatus($this->t('Hard-bounce recovery recorded. Other suppression protections remain in force.'));
    }
    catch (\InvalidArgumentException $exception) {
      $this->messenger()->addError($this->t('Evidence changed or is no longer eligible. Inspect the address again.'));
    }
    $form_state->setRedirect('postmark_webhooks.inspector');
  }

}
