<?php

declare(strict_types=1);

namespace Drupal\postmark_webhooks\Diagnostics;

use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Extension\ModuleHandlerInterface;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Drupal\Core\State\StateInterface;

/**
 * Emits deduplicated health alerts without sending mail.
 *
 * @internal
 */
final class HealthAlerts {

  /**
   * Constructs the notifier.
   */
  public function __construct(
    private readonly HealthEvaluator $health,
    private readonly StateInterface $state,
    private readonly ConfigFactoryInterface $configFactory,
    private readonly ModuleHandlerInterface $moduleHandler,
    private readonly LoggerChannelFactoryInterface $loggerFactory,
    private readonly TimeInterface $time,
  ) {}

  /**
   * Evaluates health and notifies on change or after cooldown.
   *
   * @return array<string, mixed>|null
   *   The emitted payload, or NULL when suppressed.
   */
  public function notify(?int $now = NULL): ?array {
    $now ??= $this->time->getCurrentTime();
    $report = $this->health->evaluate($now);
    $cooldown = (int) ($this->configFactory->get('postmark_webhooks.settings')->get('health_alert_cooldown_seconds') ?? 3600);
    $fingerprint = $this->fingerprint($report);
    $previous = $this->state->get('postmark_webhooks.health_alert', []);
    $previous_fingerprint = is_array($previous) ? ($previous['fingerprint'] ?? '') : '';
    $last_emitted = is_array($previous) ? (int) ($previous['last_emitted'] ?? 0) : 0;
    $event = $this->eventType($report['severity'], $previous);
    if ($event === NULL) {
      return NULL;
    }
    if ($fingerprint === $previous_fingerprint && $event === 'alert') {
      if ($cooldown <= 0 || ($now - $last_emitted) < $cooldown) {
        return NULL;
      }
    }
    $payload = [
      'event' => $event,
      'severity' => $report['severity'],
      'fingerprint' => $fingerprint,
      'checks' => $report['checks'],
    ];
    $this->deliver($payload);
    $this->state->set('postmark_webhooks.health_alert', [
      'fingerprint' => $fingerprint,
      'last_emitted' => $now,
      'last_severity' => $report['severity'],
      'last_event' => $event,
    ]);
    return $payload;
  }

  /**
   * Alert on warning/error; recover when returning to ok/unknown.
   */
  private function eventType(string $severity, mixed $previous): ?string {
    $unhealthy = in_array($severity, ['warning', 'error'], TRUE);
    $was = is_array($previous) ? (string) ($previous['last_severity'] ?? 'ok') : 'ok';
    $was_unhealthy = in_array($was, ['warning', 'error'], TRUE);
    if ($unhealthy) {
      return 'alert';
    }
    if ($was_unhealthy) {
      return 'recovery';
    }
    return NULL;
  }

  /**
   * Identifies the current unhealthy check set.
   */
  private function fingerprint(array $report): string {
    $parts = [];
    foreach ($report['checks'] as $check) {
      if (in_array($check['severity'], ['warning', 'error'], TRUE)) {
        $parts[] = $check['id'] . ':' . $check['status'];
      }
    }
    sort($parts);
    return hash('sha256', implode('|', $parts));
  }

  /**
   * Logs and invokes hooks; delivery failures must not abort evaluation.
   */
  private function deliver(array $payload): void {
    $logger = $this->loggerFactory->get('postmark_webhooks');
    try {
      if ($payload['event'] === 'recovery') {
        $logger->info('Postmark Webhooks health recovered.');
      }
      else {
        $logger->warning('Postmark Webhooks health @severity.', ['@severity' => $payload['severity']]);
      }
    }
    catch (\Throwable $exception) {
      // Logging failure must not hide the report from Drush or cron callers.
    }
    try {
      $this->moduleHandler->invokeAll('postmark_webhooks_health_alert', [$payload]);
    }
    catch (\Throwable $exception) {
      try {
        $logger->error('Postmark Webhooks health notification failed: @message', [
          '@message' => $exception->getMessage(),
        ]);
      }
      catch (\Throwable $ignored) {
      }
    }
  }

}
