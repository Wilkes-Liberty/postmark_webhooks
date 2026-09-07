<?php

declare(strict_types=1);

namespace Drupal\postmark_webhooks\Operator;

use Drupal\Core\Database\Connection;
use Drupal\Core\Database\Query\SelectInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\postmark_webhooks\Source\SourceContext;
use Drupal\postmark_webhooks\Suppression\SuppressionPolicyInterface;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;

/**
 * Permission-controlled lookup of retained events for one MessageID.
 *
 * @internal
 */
final class EventTimeline {

  /**
   * Events returned on one page.
   */
  public const PAGE_SIZE = 25;

  /**
   * Record types operators may filter to.
   */
  public const EVENT_TYPES = [
    'Bounce',
    'SpamComplaint',
    'Delivery',
    'SubscriptionChange',
  ];

  /**
   * Constructs the timeline reader.
   */
  public function __construct(
    private readonly Connection $database,
    private readonly SuppressionPolicyInterface $policy,
  ) {}

  /**
   * Returns one page of retained events for an exact MessageID.
   *
   * Lookup values stay in the form, not in URLs or logs. Rows omit payload
   * and description. Delivery is provider evidence, not inbox proof.
   *
   * @param string $message_id
   *   Exact Postmark MessageID.
   * @param array $filters
   *   Optional event_type, server_id, message_stream, occurred_from and
   *   occurred_to (Unix seconds, inclusive start, exclusive end).
   * @param \Drupal\Core\Session\AccountInterface $actor
   *   Operator performing the lookup.
   * @param int $page
   *   Zero-based page index.
   *
   * @return array
   *   Page of events, total count, page index and suppression flags.
   */
  public function lookup(string $message_id, array $filters, AccountInterface $actor, int $page): array {
    $this->requireView($actor);
    $message_id = trim($message_id);
    if ($message_id === '' || strlen($message_id) > 255 || str_contains($message_id, "\0")) {
      throw new \InvalidArgumentException('Enter one exact MessageID.');
    }
    if ($page < 0) {
      throw new \InvalidArgumentException('Invalid page.');
    }
    $filters = $this->normalizeFilters($filters);
    $total = (int) $this->baseQuery($message_id, $filters)->countQuery()->execute()->fetchField();
    $pages = $total === 0 ? 0 : (int) ceil($total / self::PAGE_SIZE);
    if ($pages > 0 && $page >= $pages) {
      throw new \InvalidArgumentException('Invalid page.');
    }
    $rows = [];
    if ($total > 0) {
      $query = $this->baseQuery($message_id, $filters);
      $query->fields('e', [
        'eid',
        'created',
        'occurred',
        'time_basis',
        'event_type',
        'bounce_type',
        'server_id',
        'message_stream',
        'recipient',
      ]);
      $query->orderBy('occurred', 'ASC');
      $query->orderBy('created', 'ASC');
      $query->orderBy('eid', 'ASC');
      $query->range($page * self::PAGE_SIZE, self::PAGE_SIZE);
      $rows = $query->execute()->fetchAll();
    }
    $recipients = [];
    foreach ($rows as $row) {
      $recipients[$row->recipient] = $this->policy->decide($row->recipient)->suppressed;
    }
    return [
      'message_id' => $message_id,
      'events' => $rows,
      'total' => $total,
      'page' => $page,
      'pages' => $pages,
      'suppressed' => $recipients,
    ];
  }

  /**
   * Validates optional filters and rejects partial source pairs.
   *
   * @param array $filters
   *   Raw operator filters.
   *
   * @return array
   *   Normalized filters used in the query.
   */
  private function normalizeFilters(array $filters): array {
    $type = $filters['event_type'] ?? '';
    if ($type !== '' && !in_array($type, self::EVENT_TYPES, TRUE)) {
      throw new \InvalidArgumentException('Unknown event type.');
    }
    $server = trim((string) ($filters['server_id'] ?? ''));
    $stream = trim((string) ($filters['message_stream'] ?? ''));
    if (($server === '') !== ($stream === '')) {
      throw new \InvalidArgumentException('Provide both source fields or leave both blank.');
    }
    if ($server !== '') {
      new SourceContext($server, $stream);
    }
    $from = $filters['occurred_from'] ?? NULL;
    $to = $filters['occurred_to'] ?? NULL;
    if ($from !== NULL && (!is_int($from) || $from < 0)) {
      throw new \InvalidArgumentException('Invalid start time.');
    }
    if ($to !== NULL && (!is_int($to) || $to < 0)) {
      throw new \InvalidArgumentException('Invalid end time.');
    }
    if ($from !== NULL && $to !== NULL && $from >= $to) {
      throw new \InvalidArgumentException('The start time must be before the end time.');
    }
    return [
      'event_type' => $type,
      'server_id' => $server,
      'message_stream' => $stream,
      'occurred_from' => $from,
      'occurred_to' => $to,
    ];
  }

  /**
   * Builds the shared MessageID query without selected columns.
   */
  private function baseQuery(string $message_id, array $filters): SelectInterface {
    $query = $this->database->select('postmark_events', 'e');
    $query->condition('message_id', $message_id);
    if ($filters['event_type'] !== '') {
      $query->condition('event_type', $filters['event_type']);
    }
    if ($filters['server_id'] !== '') {
      $query->condition('server_id', $filters['server_id']);
      $query->condition('message_stream', $filters['message_stream']);
    }
    if ($filters['occurred_from'] !== NULL) {
      $query->condition('occurred', $filters['occurred_from'], '>=');
    }
    if ($filters['occurred_to'] !== NULL) {
      $query->condition('occurred', $filters['occurred_to'], '<');
    }
    return $query;
  }

  /**
   * Enforces the view permission for every service entry point.
   */
  private function requireView(AccountInterface $actor): void {
    if (!$actor->hasPermission('view postmark suppression')) {
      throw new AccessDeniedHttpException();
    }
  }

}
