<?php

declare(strict_types=1);

namespace Drupal\postmark_webhooks\Form;

use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\postmark_webhooks\Operator\EventTimeline;
use Drupal\postmark_webhooks\Source\SourceContext;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Protected MessageID timeline that keeps identifiers out of lookup URLs.
 *
 * @internal
 */
final class EventTimelineForm extends FormBase {

  use OperatorFormTrait;

  /**
   * Constructs the timeline form.
   */
  public function __construct(protected EventTimeline $timeline) {}

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): self {
    return new self($container->get('postmark_webhooks.event_timeline'));
  }

  /**
   * {@inheritdoc}
   */
  public function getFormId(): string {
    return 'postmark_webhooks_event_timeline';
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state): array {
    $this->operatorShell(
      $form,
      'postmark-webhooks-timeline-help',
      $this->t('This timeline uses retained local events only. It is not a complete provider archive. Delivery is provider evidence, not proof of inbox placement, and does not override suppression. Message identifiers are submitted in this form, not in lookup URLs.'),
    );
    $types = ['' => $this->t('- Any -')];
    foreach (EventTimeline::EVENT_TYPES as $type) {
      $types[$type] = $type;
    }
    $form['message_id'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Message ID'),
      '#required' => TRUE,
      '#maxlength' => 255,
      '#description' => $this->t('Exact Postmark MessageID. Wildcards are not supported.'),
    ];
    $form['event_type'] = [
      '#type' => 'select',
      '#title' => $this->t('Event type'),
      '#options' => $types,
    ];
    $form['server_id'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Server ID'),
      '#maxlength' => 20,
    ];
    $form['message_stream'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Message stream'),
      '#maxlength' => 255,
      '#description' => $this->t('Provide both source fields or leave both blank.'),
    ];
    $form['occurred_from'] = [
      '#type' => 'date',
      '#title' => $this->t('Occurred from (UTC)'),
    ];
    $form['occurred_to'] = [
      '#type' => 'date',
      '#title' => $this->t('Occurred to (UTC)'),
    ];
    $form['actions']['#type'] = 'actions';
    $form['actions']['submit'] = [
      '#type' => 'submit',
      '#value' => $this->t('Look up timeline'),
      '#name' => 'op_lookup',
    ];
    $result = $form_state->get('timeline');
    if (!is_array($result)) {
      return $form;
    }
    $form['result'] = $this->operatorResult(
      'postmark-webhooks-timeline-result',
      $this->t('Timeline result'),
    );
    $form['result']['archive'] = [
      '#type' => 'container',
      '#attributes' => [
        'class' => ['postmark-webhooks-operator__help'],
        'role' => 'note',
      ],
      'text' => [
        '#markup' => $this->t('History may have been purged or may predate installation. Missing events are not proof that Postmark has no record.'),
      ],
    ];
    if ($result['total'] === 0) {
      $form['result']['empty'] = [
        '#type' => 'container',
        '#attributes' => ['role' => 'status'],
        'text' => [
          '#markup' => $this->t('No retained events match this lookup. The identifier may be wrong, the history may have been purged, or the message may predate installation.'),
        ],
      ];
      return $form;
    }
    $form['result']['summary'] = [
      '#type' => 'item',
      '#title' => $this->t('Message timeline'),
      '#markup' => $this->t('@count retained events, page @page of @pages. Events are grouped by MessageID and ordered by provider occurrence, then receipt time.', [
        '@count' => $result['total'],
        '@page' => $result['page'] + 1,
        '@pages' => $result['pages'],
      ]),
      '#wrapper_attributes' => ['role' => 'status'],
    ];
    foreach ($result['events'] as $index => $event) {
      $suppressed = !empty($result['suppressed'][$event->recipient]);
      $form['result']['events'][$index] = [
        '#type' => 'details',
        '#title' => $this->t('@type — @time (@basis)', [
          '@type' => $event->event_type,
          '@time' => gmdate('Y-m-d H:i:s \U\T\C', (int) $event->occurred),
          '@basis' => $event->time_basis,
        ]),
        '#open' => TRUE,
      ];
      $form['result']['events'][$index]['identity'] = [
        '#type' => 'item',
        '#title' => $this->t('Event identity'),
        '#markup' => $this->t('Distinct from the MessageID. Receipt @receipt.', [
          '@receipt' => gmdate('Y-m-d H:i:s \U\T\C', (int) $event->created),
        ]),
      ];
      $form['result']['events'][$index]['source'] = [
        '#type' => 'item',
        '#title' => $this->t('Source'),
        '#plain_text' => $event->server_id . ' / ' . $event->message_stream,
      ];
      $form['result']['events'][$index]['recipient'] = [
        '#type' => 'item',
        '#title' => $this->t('Recipient'),
        '#plain_text' => $event->recipient,
      ];
      if ($event->bounce_type !== '') {
        $form['result']['events'][$index]['bounce'] = [
          '#type' => 'item',
          '#title' => $this->t('Bounce type'),
          '#plain_text' => $event->bounce_type,
        ];
      }
      if ($event->event_type === 'Delivery') {
        $form['result']['events'][$index]['delivery'] = [
          '#type' => 'item',
          '#title' => $this->t('Delivery evidence'),
          '#markup' => $this->t('This is provider evidence, not proof of inbox placement. Delivery does not override suppression.'),
        ];
      }
      if ($suppressed) {
        $form['result']['events'][$index]['suppression'] = [
          '#type' => 'item',
          '#title' => $this->t('Suppression'),
          '#markup' => $this->t('This recipient currently has active suppression.'),
        ];
      }
    }
    if ($result['page'] > 0) {
      $form['actions']['previous'] = [
        '#type' => 'submit',
        '#value' => $this->t('Previous page'),
        '#name' => 'op_previous',
      ];
    }
    if ($result['page'] + 1 < $result['pages']) {
      $form['actions']['next'] = [
        '#type' => 'submit',
        '#value' => $this->t('Next page'),
        '#name' => 'op_next',
      ];
    }
    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function validateForm(array &$form, FormStateInterface $form_state): void {
    $message_id = trim((string) $form_state->getValue('message_id'));
    if ($message_id === '' || strlen($message_id) > 255 || str_contains($message_id, "\0")) {
      $form_state->setErrorByName('message_id', $this->t('Enter one exact MessageID.'));
    }
    $type = (string) $form_state->getValue('event_type');
    if ($type !== '' && !in_array($type, EventTimeline::EVENT_TYPES, TRUE)) {
      $form_state->setErrorByName('event_type', $this->t('Unknown event type.'));
    }
    $server = trim((string) $form_state->getValue('server_id'));
    $stream = trim((string) $form_state->getValue('message_stream'));
    if (($server === '') !== ($stream === '')) {
      $form_state->setErrorByName('server_id', $this->t('Provide both source fields or leave both blank.'));
      $form_state->setErrorByName('message_stream', $this->t('Provide both source fields or leave both blank.'));
    }
    elseif ($server !== '') {
      try {
        new SourceContext($server, $stream);
      }
      catch (\InvalidArgumentException) {
        $source_error = $this->t('Enter a numeric server ID of at most 20 digits and a non-empty message stream of at most 255 characters.');
        $form_state->setErrorByName('server_id', $source_error);
        $form_state->setErrorByName('message_stream', $source_error);
      }
    }
    $from = NULL;
    $to = NULL;
    try {
      $from = $this->dayStart($form_state->getValue('occurred_from'));
    }
    catch (\InvalidArgumentException) {
      $form_state->setErrorByName('occurred_from', $this->t('Invalid start time.'));
    }
    try {
      $to = $this->dayEnd($form_state->getValue('occurred_to'));
    }
    catch (\InvalidArgumentException) {
      $form_state->setErrorByName('occurred_to', $this->t('Invalid end time.'));
    }
    if ($from !== NULL && $to !== NULL && $from >= $to) {
      $form_state->setErrorByName('occurred_from', $this->t('The start time must be before the end time.'));
      $form_state->setErrorByName('occurred_to', $this->t('The start time must be before the end time.'));
    }
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state): void {
    $form_state->setRebuild();
    $op = $form_state->getTriggeringElement()['#name'] ?? 'op_lookup';
    $page = (int) ($form_state->get('page') ?? 0);
    if ($op === 'op_lookup') {
      $page = 0;
    }
    elseif ($op === 'op_next') {
      $page++;
    }
    elseif ($op === 'op_previous') {
      $page = max(0, $page - 1);
    }
    try {
      $result = $this->timeline->lookup(
        (string) $form_state->getValue('message_id'),
        $this->filters($form_state),
        $this->currentUser(),
        $page,
      );
    }
    catch (\InvalidArgumentException $exception) {
      $this->messenger()->addError($exception->getMessage());
      $form_state->set('timeline', NULL);
      $form_state->set('page', 0);
      return;
    }
    $form_state->set('timeline', $result);
    $form_state->set('page', $result['page']);
  }

  /**
   * Converts form values into service filters.
   */
  private function filters(FormStateInterface $form_state): array {
    $from = $form_state->getValue('occurred_from');
    $to = $form_state->getValue('occurred_to');
    return [
      'event_type' => (string) $form_state->getValue('event_type'),
      'server_id' => (string) $form_state->getValue('server_id'),
      'message_stream' => (string) $form_state->getValue('message_stream'),
      'occurred_from' => $this->dayStart($from),
      'occurred_to' => $this->dayEnd($to),
    ];
  }

  /**
   * Inclusive UTC start of a Y-m-d value, or NULL when empty.
   */
  private function dayStart(mixed $value): ?int {
    if (!is_string($value) || $value === '') {
      return NULL;
    }
    $date = \DateTimeImmutable::createFromFormat('Y-m-d', $value, new \DateTimeZone('UTC'));
    if (!$date || $date->format('Y-m-d') !== $value) {
      throw new \InvalidArgumentException('Invalid start time.');
    }
    return $date->setTime(0, 0, 0)->getTimestamp();
  }

  /**
   * Exclusive UTC end of a Y-m-d value (start of the next day), or NULL.
   */
  private function dayEnd(mixed $value): ?int {
    if (!is_string($value) || $value === '') {
      return NULL;
    }
    $date = \DateTimeImmutable::createFromFormat('Y-m-d', $value, new \DateTimeZone('UTC'));
    if (!$date || $date->format('Y-m-d') !== $value) {
      throw new \InvalidArgumentException('Invalid end time.');
    }
    return $date->setTime(0, 0, 0)->modify('+1 day')->getTimestamp();
  }

}
