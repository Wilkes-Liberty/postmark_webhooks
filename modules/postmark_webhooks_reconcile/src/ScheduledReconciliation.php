<?php

declare(strict_types=1);

namespace Drupal\postmark_webhooks_reconcile;

use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Database\Connection;
use Drupal\Core\Extension\ModuleHandlerInterface;
use Drupal\Core\Lock\LockBackendInterface;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Drupal\Core\State\StateInterface;
use Drupal\postmark_webhooks\Source\SourceContext;

/**
 * Opt-in scheduled drift previews that never apply imports or provider writes.
 *
 * @internal
 */
final class ScheduledReconciliation {

  /**
   * Constructs the scheduler.
   */
  public function __construct(
    private readonly Reconciliation $reconciliation,
    private readonly Connection $database,
    private readonly ConfigFactoryInterface $configFactory,
    private readonly StateInterface $state,
    private readonly LockBackendInterface $lock,
    private readonly ModuleHandlerInterface $moduleHandler,
    private readonly LoggerChannelFactoryInterface $loggerFactory,
    private readonly TimeInterface $time,
  ) {}

  /**
   * Lock name for one configured source.
   */
  public static function lockName(SourceContext $source): string {
    return 'pmwh_rec_' . substr(hash('sha256', $source->serverId . "\0" . $source->messageStream), 0, 40);
  }

  /**
   * Runs at most one bounded page of dates per configured source.
   */
  public function run(?int $now = NULL): array {
    $now ??= $this->time->getCurrentTime();
    $result = [
      'ran' => FALSE,
      'reason' => '',
      'sources' => [],
      'local_suppression_changed' => FALSE,
      'provider_writes' => FALSE,
    ];
    if (!$this->database->schema()->tableExists('postmark_drift_report')) {
      $result['reason'] = 'schema';
      return $result;
    }
    $config = $this->configFactory->get('postmark_webhooks_reconcile.settings');
    if (!(bool) ($config->get('enabled') ?? FALSE)) {
      $result['reason'] = 'disabled';
      return $result;
    }
    $lookback = max(1, min(30, (int) ($config->get('lookback_days') ?? 1)));
    $interval = max(0, min(604800, (int) ($config->get('interval_seconds') ?? 86400)));
    $per_run = max(1, min(7, (int) ($config->get('dates_per_run') ?? 1)));
    $dates = $this->window($now, $lookback);
    $sources = $this->sources($config->get('sources'));
    if ($sources === []) {
      $result['reason'] = 'no_sources';
      return $result;
    }
    $result['ran'] = TRUE;
    foreach ($sources as $source) {
      $result['sources'][] = $this->runSource($source, $dates, $now, $interval, $per_run);
    }
    return $result;
  }

  /**
   * Returns recent reports without recipient or secret labels.
   */
  public function reports(int $limit = 20): array {
    if (!$this->database->schema()->tableExists('postmark_drift_report')) {
      return [];
    }
    $limit = max(1, min(100, $limit));
    $result = $this->database->select('postmark_drift_report', 'r')
      ->fields('r')
      ->orderBy('created', 'DESC')
      ->range(0, $limit)
      ->execute();
    $reports = [];
    while ($row = $result->fetchAssoc()) {
      $reports[] = $this->present($row);
    }
    return $reports;
  }

  /**
   * Deletes at most 250 expired reports; suppression and reviews are untouched.
   */
  public function prune(?int $now = NULL): void {
    if (!$this->database->schema()->tableExists('postmark_drift_report')) {
      return;
    }
    $now ??= $this->time->getCurrentTime();
    $retention = (int) ($this->configFactory->get('postmark_webhooks_reconcile.settings')->get('report_retention_seconds') ?? 604800);
    if ($retention <= 0) {
      return;
    }
    $ids = $this->database->select('postmark_drift_report', 'r')->fields('r', ['report_id'])
      ->condition('created', $now - $retention, '<=')
      ->orderBy('created')
      ->range(0, 250)
      ->execute()
      ->fetchCol();
    if ($ids) {
      $this->database->delete('postmark_drift_report')->condition('report_id', $ids, 'IN')->execute();
    }
  }

  /**
   * Scans one source under a lock, resuming the date cursor.
   */
  private function runSource(SourceContext $source, array $dates, int $now, int $interval, int $per_run): array {
    $summary = [
      'server_id' => $source->serverId,
      'message_stream' => $source->messageStream,
      'skipped' => '',
      'scanned' => [],
    ];
    $name = self::lockName($source);
    if (!$this->lock->acquire($name, 300.0)) {
      $summary['skipped'] = 'lock';
      return $summary;
    }
    try {
      $key = self::progressKey($source);
      $all = $this->state->get('postmark_webhooks_reconcile.schedule', []);
      $progress = is_array($all[$key] ?? NULL) ? $all[$key] : [];
      if ((int) ($progress['retry_after'] ?? 0) > $now) {
        $summary['skipped'] = 'retry';
        return $summary;
      }
      $window_start = $dates[0];
      $window_end = $dates[array_key_last($dates)];
      $same_window = ($progress['window_start'] ?? '') === $window_start
        && ($progress['window_end'] ?? '') === $window_end;
      $cursor = $same_window ? (string) ($progress['cursor'] ?? $window_start) : $window_start;
      if ($cursor === 'done') {
        if ($interval > 0 && (int) ($progress['last_completed'] ?? 0) > 0
          && ($now - (int) $progress['last_completed']) < $interval) {
          $summary['skipped'] = 'interval';
          return $summary;
        }
        $cursor = $window_start;
      }
      $remaining = $per_run;
      foreach ($dates as $date) {
        if ($date < $cursor) {
          continue;
        }
        if ($remaining <= 0) {
          break;
        }
        if ($this->hasTerminalReport($source, $date)) {
          $cursor = $this->nextDate($dates, $date);
          $summary['scanned'][] = ['source_date' => $date, 'status' => 'cached'];
          continue;
        }
        $report = $this->scanDate($source, $date, $now);
        $summary['scanned'][] = [
          'source_date' => $date,
          'status' => $report['status'],
          'incomplete_reason' => $report['incomplete_reason'],
          'report_id' => $report['report_id'],
        ];
        if ($report['status'] === 'incomplete' && $report['incomplete_reason'] !== 'oversized') {
          $progress['retry_after'] = $report['retry_after'] ?? ($now + 60);
          $progress['cursor'] = $date;
          $progress['window_start'] = $window_start;
          $progress['window_end'] = $window_end;
          $all[$key] = $progress;
          $this->state->set('postmark_webhooks_reconcile.schedule', $all);
          return $summary;
        }
        $cursor = $this->nextDate($dates, $date);
        $remaining--;
      }
      $progress['window_start'] = $window_start;
      $progress['window_end'] = $window_end;
      $progress['retry_after'] = 0;
      if ($cursor === 'done') {
        $progress['cursor'] = 'done';
        $progress['last_completed'] = $now;
      }
      else {
        $progress['cursor'] = $cursor;
      }
      $all[$key] = $progress;
      $this->state->set('postmark_webhooks_reconcile.schedule', $all);
      return $summary;
    }
    finally {
      $this->lock->release($name);
    }
  }

  /**
   * Fetches one date and stores a privacy-safe report.
   */
  private function scanDate(SourceContext $source, string $date, int $now): array {
    try {
      $comparison = $this->reconciliation->compare($source, $date);
      $report = $this->store($comparison, $now, 0);
      $this->notify($report);
      return $report;
    }
    catch (ProviderReadException | \Throwable $exception) {
      $reason = $exception instanceof ProviderReadException ? $this->reason($exception) : 'error';
      $retry = $exception instanceof ProviderReadException ? ($exception->retryAfter ?? 60) : 60;
      $comparison = [
        'server_id' => $source->serverId,
        'message_stream' => $source->messageStream,
        'source_date' => $date,
        'digest' => '',
        'provider_total' => 0,
        'status' => 'incomplete',
        'incomplete_reason' => $reason,
        'differences' => ['new_evidence' => 0, 'newer_evidence' => 0, 'unchanged_or_older' => 0],
        'reasons' => [],
        'local_suppression_changed' => FALSE,
        'provider_writes' => FALSE,
        'absence_clears_local' => FALSE,
      ];
      $report = $this->store($comparison, $now, $now + max(1, min(3600, $retry)));
      $this->logIncomplete($reason);
      return $report;
    }
  }

  /**
   * Persists one report, replacing any prior row for the same source and date.
   */
  private function store(array $comparison, int $now, int $retry_after): array {
    $this->database->delete('postmark_drift_report')
      ->condition('server_id', $comparison['server_id'])
      ->condition('message_stream', $comparison['message_stream'])
      ->condition('source_date', $comparison['source_date'])
      ->execute();
    $row = [
      'report_id' => bin2hex(random_bytes(16)),
      'server_id' => $comparison['server_id'],
      'message_stream' => $comparison['message_stream'],
      'source_date' => $comparison['source_date'],
      'created' => $now,
      'status' => $comparison['status'],
      'incomplete_reason' => $comparison['incomplete_reason'],
      'new_evidence' => (int) $comparison['differences']['new_evidence'],
      'newer_evidence' => (int) $comparison['differences']['newer_evidence'],
      'unchanged_or_older' => (int) $comparison['differences']['unchanged_or_older'],
      'provider_total' => (int) $comparison['provider_total'],
      'digest' => $comparison['digest'],
      'reasons' => json_encode($comparison['reasons'], JSON_THROW_ON_ERROR),
    ];
    $this->database->insert('postmark_drift_report')->fields($row)->execute();
    return $this->present($row) + ['retry_after' => $retry_after];
  }

  /**
   * Formats a stored row without provider bodies or mailbox labels.
   */
  private function present(array $row): array {
    $reasons = json_decode((string) $row['reasons'], TRUE);
    return [
      'report_id' => $row['report_id'],
      'server_id' => $row['server_id'],
      'message_stream' => $row['message_stream'],
      'source_date' => $row['source_date'],
      'created' => (int) $row['created'],
      'status' => $row['status'],
      'incomplete_reason' => $row['incomplete_reason'],
      'differences' => [
        'new_evidence' => (int) $row['new_evidence'],
        'newer_evidence' => (int) $row['newer_evidence'],
        'unchanged_or_older' => (int) $row['unchanged_or_older'],
      ],
      'reasons' => is_array($reasons) ? $reasons : [],
      'provider_total' => (int) $row['provider_total'],
      'digest' => $row['digest'],
      'local_suppression_changed' => FALSE,
      'provider_writes' => FALSE,
      'absence_clears_local' => FALSE,
    ];
  }

  /**
   * Maps a sanitized reader failure to a stable incomplete reason.
   */
  private function reason(ProviderReadException $exception): string {
    if ($exception->retryAfter !== NULL) {
      return 'rate_limit';
    }
    $message = $exception->getMessage();
    if (str_contains($message, 'token') || str_contains($message, 'HTTP 401') || str_contains($message, 'HTTP 403')) {
      return 'credentials';
    }
    if (str_contains($message, '4 MiB') || str_contains($message, '10000')) {
      return 'oversized';
    }
    if (str_contains($message, 'intake policy')) {
      return 'policy';
    }
    if (str_contains($message, 'request failed') || str_contains($message, 'could not be read') || str_contains($message, 'stopped before')) {
      return 'timeout';
    }
    return 'error';
  }

  /**
   * Notifies on complete drift; delivery failures do not fail the scan.
   */
  private function notify(array $report): void {
    if (!in_array($report['status'], ['complete', 'empty'], TRUE)) {
      return;
    }
    $drift = $report['differences']['new_evidence'] + $report['differences']['newer_evidence'];
    if ($drift <= 0) {
      return;
    }
    $cooldown = (int) ($this->configFactory->get('postmark_webhooks_reconcile.settings')->get('alert_cooldown_seconds') ?? 3600);
    $fingerprint = hash('sha256', json_encode([
      $report['server_id'],
      $report['message_stream'],
      $report['source_date'],
      $report['digest'],
      $report['differences'],
    ], JSON_THROW_ON_ERROR));
    $previous = $this->state->get('postmark_webhooks_reconcile.drift_alert', []);
    $source_key = json_encode([$report['server_id'], $report['message_stream']], JSON_THROW_ON_ERROR);
    $last = is_array($previous[$source_key] ?? NULL) ? $previous[$source_key] : [];
    $last_fingerprint = (string) ($last['fingerprint'] ?? '');
    $last_emitted = (int) ($last['last_emitted'] ?? 0);
    if ($last_fingerprint === $fingerprint && ($cooldown <= 0 || ($report['created'] - $last_emitted) < $cooldown)) {
      return;
    }
    $logger = $this->loggerFactory->get('postmark_webhooks');
    try {
      $logger->info('Postmark Webhooks drift report @status with new or newer evidence.', [
        '@status' => $report['status'],
      ]);
    }
    catch (\Throwable $exception) {
    }
    try {
      $this->moduleHandler->invokeAll('postmark_webhooks_drift_report', [$report]);
    }
    catch (\Throwable $exception) {
      try {
        $logger->error('Postmark Webhooks drift notification failed: @message', [
          '@message' => $exception->getMessage(),
        ]);
      }
      catch (\Throwable $ignored) {
      }
    }
    if (!is_array($previous)) {
      $previous = [];
    }
    $previous[$source_key] = [
      'fingerprint' => $fingerprint,
      'last_emitted' => $report['created'],
    ];
    $this->state->set('postmark_webhooks_reconcile.drift_alert', $previous);
  }

  /**
   * Logs incomplete scans without tokens or provider bodies.
   */
  private function logIncomplete(string $reason): void {
    try {
      $this->loggerFactory->get('postmark_webhooks')->warning('Postmark Webhooks scheduled reconciliation was incomplete: @reason.', [
        '@reason' => $reason,
      ]);
    }
    catch (\Throwable $exception) {
    }
  }

  /**
   * Completed UTC dates from lookback through yesterday.
   */
  private function window(int $now, int $lookback): array {
    $end = (new \DateTimeImmutable('@' . $now))->setTimezone(new \DateTimeZone('UTC'))->setTime(0, 0)->modify('-1 day');
    $start = $end->modify('-' . ($lookback - 1) . ' days');
    $dates = [];
    for ($date = $start; $date <= $end; $date = $date->modify('+1 day')) {
      $dates[] = $date->format('Y-m-d');
    }
    return $dates;
  }

  /**
   * Validates configured sources; invalid rows are ignored.
   */
  private function sources(mixed $configured): array {
    if (!is_array($configured) || !array_is_list($configured)) {
      return [];
    }
    $sources = [];
    $seen = [];
    foreach ($configured as $row) {
      if (!is_array($row) || (!is_string($row['server_id'] ?? NULL) && !is_int($row['server_id'] ?? NULL))
        || !is_string($row['message_stream'] ?? NULL)) {
        continue;
      }
      try {
        $source = new SourceContext((string) $row['server_id'], $row['message_stream']);
      }
      catch (\InvalidArgumentException $exception) {
        continue;
      }
      $key = self::progressKey($source);
      if (isset($seen[$key])) {
        continue;
      }
      $seen[$key] = TRUE;
      $sources[] = $source;
    }
    return $sources;
  }

  /**
   * Whether this date already has a complete, empty, or oversized report.
   */
  private function hasTerminalReport(SourceContext $source, string $date): bool {
    $row = $this->database->select('postmark_drift_report', 'r')
      ->fields('r', ['status', 'incomplete_reason'])
      ->condition('server_id', $source->serverId)
      ->condition('message_stream', $source->messageStream)
      ->condition('source_date', $date)
      ->execute()
      ->fetchAssoc();
    if (!$row) {
      return FALSE;
    }
    return in_array($row['status'], ['complete', 'empty'], TRUE)
      || ($row['status'] === 'incomplete' && $row['incomplete_reason'] === 'oversized');
  }

  /**
   * Next date in the window, or done.
   */
  private function nextDate(array $dates, string $current): string {
    $index = array_search($current, $dates, TRUE);
    if ($index === FALSE || !isset($dates[$index + 1])) {
      return 'done';
    }
    return $dates[$index + 1];
  }

  /**
   * State key for one source cursor.
   */
  private static function progressKey(SourceContext $source): string {
    return json_encode([$source->serverId, $source->messageStream], JSON_THROW_ON_ERROR);
  }

}
