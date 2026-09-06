<?php

declare(strict_types=1);

namespace Drupal\postmark_webhooks\Diagnostics;

use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Extension\ModuleHandlerInterface;
use Drupal\Core\Site\Settings;
use Drupal\postmark_webhooks\Authentication\WebhookCredentials;
use Drupal\postmark_webhooks\Retention\EventRetention;

/**
 * Evaluates operational health without recipient or secret labels.
 *
 * @internal
 */
final class HealthEvaluator {

  /**
   * Constructs the evaluator.
   */
  public function __construct(
    private readonly ConfigFactoryInterface $configFactory,
    private readonly TimeInterface $time,
    private readonly IntakeMetrics $metrics,
    private readonly EventRetention $retention,
    private readonly ModuleHandlerInterface $moduleHandler,
  ) {}

  /**
   * Returns a machine-readable health report.
   */
  public function evaluate(?int $now = NULL): array {
    $now ??= $this->time->getCurrentTime();
    $config = $this->configFactory->get('postmark_webhooks.settings');
    $expected = (int) ($config->get('health_expected_activity_seconds') ?? 0);
    $backlog_limit = (int) ($config->get('health_retention_backlog_warning') ?? 250);
    $rotation_warning = (int) ($config->get('health_rotation_warning_seconds') ?? 86400);
    $credentials = WebhookCredentials::fromSettings();
    $intake = $this->metrics->snapshot();
    $last_accepted = $intake['accepted']['last_seen'];
    $retention_days = (int) ($config->get('event_retention_days') ?? 90);
    $expired = 0;
    if ($retention_days > 0) {
      $expired = $this->retention->expiredCount($now - $retention_days * 86400);
    }

    $checks = [
      $this->secretCheck($credentials),
      $this->rotationCheck($credentials, $now, $rotation_warning),
      $this->silenceCheck($last_accepted, $now, $expected),
      $this->backlogCheck($expired, $backlog_limit, $retention_days),
      $this->sourceCheck(),
      $this->reachabilityCheck(),
      $this->providerCheck(),
    ];
    $severity = 'ok';
    foreach ($checks as $check) {
      $severity = $this->worse($severity, $check['severity']);
    }
    return [
      'severity' => $severity,
      'endpoint_reachability' => 'unproven',
      'checks' => $checks,
      'intake' => [
        'last_accepted' => $last_accepted,
        'expected_activity_seconds' => $expected,
      ],
      'retention' => [
        'expired_rows' => $expired,
        'warning_threshold' => $backlog_limit,
      ],
      'rotation' => [
        'status' => $credentials->rotationStatus($now),
        'expires_in' => $this->expiresIn($credentials, $now),
      ],
    ];
  }

  /**
   * Compact summary for diagnostics JSON.
   */
  public function summary(?int $now = NULL): array {
    $report = $this->evaluate($now);
    $checks = [];
    foreach ($report['checks'] as $check) {
      $checks[$check['id']] = $check['severity'];
    }
    return [
      'severity' => $report['severity'],
      'endpoint_reachability' => $report['endpoint_reachability'],
      'checks' => $checks,
    ];
  }

  /**
   * Active secret must be present for intake.
   */
  private function secretCheck(WebhookCredentials $credentials): array {
    if ($credentials->isConfigured()) {
      return $this->check('secret', 'ok', 'configured', 'The active webhook secret is configured in settings.');
    }
    return $this->check('secret', 'error', 'missing', 'The active webhook secret is missing or invalid; intake returns 503.');
  }

  /**
   * Warns when a previous secret is invalid or close to expiry.
   */
  private function rotationCheck(WebhookCredentials $credentials, int $now, int $warning): array {
    $status = $credentials->rotationStatus($now);
    if ($status === 'invalid') {
      return $this->check('rotation', 'warning', 'invalid', 'The previous webhook secret setting is present but unusable.');
    }
    if ($status === 'expired') {
      return $this->check('rotation', 'ok', 'expired', 'The previous webhook secret has expired and is no longer accepted.');
    }
    if ($status === 'active') {
      $remaining = $this->expiresIn($credentials, $now);
      if ($warning > 0 && $remaining !== NULL && $remaining <= $warning) {
        return $this->check('rotation', 'warning', 'expiring', 'The previous webhook secret expires soon.');
      }
      return $this->check('rotation', 'ok', 'active', 'A previous webhook secret is accepted until its fixed expiry.');
    }
    return $this->check('rotation', 'ok', 'absent', 'No previous webhook secret is configured.');
  }

  /**
   * Quiet sites stay unknown unless an activity window is configured.
   */
  private function silenceCheck(?int $last_accepted, int $now, int $expected): array {
    if ($expected <= 0) {
      return $this->check('intake_silence', 'unknown', 'not_configured', 'No expected activity window is configured; silence is not treated as a failure.');
    }
    if ($last_accepted === NULL) {
      return $this->check('intake_silence', 'warning', 'no_traffic', 'No accepted intake has been recorded within the configured activity window.');
    }
    if ($now - $last_accepted > $expected) {
      return $this->check('intake_silence', 'warning', 'stale', 'Last accepted intake is older than the configured activity window.');
    }
    return $this->check('intake_silence', 'ok', 'fresh', 'Accepted intake is within the configured activity window.');
  }

  /**
   * Warns when expired history exceeds the operator threshold.
   */
  private function backlogCheck(int $expired, int $limit, int $retention_days): array {
    if ($retention_days <= 0 || $limit <= 0) {
      return $this->check('retention_backlog', 'ok', 'not_configured', 'Retention backlog warnings are disabled.');
    }
    if ($expired > $limit) {
      return $this->check('retention_backlog', 'warning', 'backlogged', 'Expired event history exceeds the warning threshold.');
    }
    return $this->check('retention_backlog', 'ok', 'within_limit', 'Expired event history is within the warning threshold.');
  }

  /**
   * Aggregate counters cannot describe per-source health.
   */
  private function sourceCheck(): array {
    $allowlist = Settings::get('postmark_webhooks.allowed_sources');
    if ($allowlist === NULL) {
      return $this->check('source_health', 'unknown', 'shared_endpoint', 'Intake counters are site-wide and do not identify a source.');
    }
    return $this->check('source_health', 'unknown', 'aggregate_only', 'A source allowlist is configured, but counters still have no source labels.');
  }

  /**
   * Local success never proves Postmark can reach the endpoint.
   */
  private function reachabilityCheck(): array {
    return $this->check('endpoint_reachability', 'unknown', 'unproven', 'Local counters do not prove endpoint reachability.');
  }

  /**
   * Optional modules may report provider-paused delivery.
   */
  private function providerCheck(): array {
    $results = $this->moduleHandler->invokeAll('postmark_webhooks_provider_health');
    $status = '';
    if (is_string($results['status'] ?? NULL)) {
      $status = $results['status'];
    }
    else {
      foreach ($results as $result) {
        if (is_array($result) && is_string($result['status'] ?? NULL)) {
          $status = $result['status'];
          break;
        }
      }
    }
    if ($status === 'paused') {
      return $this->check('provider_delivery', 'warning', 'paused', 'A provider health hook reported paused delivery.');
    }
    if ($status === 'error') {
      return $this->check('provider_delivery', 'warning', 'error', 'A provider health hook reported a provider error.');
    }
    if ($status === 'available') {
      return $this->check('provider_delivery', 'ok', 'available', 'A provider health hook reported available delivery.');
    }
    return $this->check('provider_delivery', 'unknown', 'not_queried', 'Provider delivery status was not queried.');
  }

  /**
   * Builds one privacy-safe check row.
   */
  private function check(string $id, string $severity, string $status, string $detail): array {
    return [
      'id' => $id,
      'severity' => $severity,
      'status' => $status,
      'detail' => $detail,
    ];
  }

  /**
   * Seconds until the previous secret expires.
   */
  private function expiresIn(WebhookCredentials $credentials, int $now): ?int {
    $expires = $credentials->previousExpiresAt();
    if ($expires === NULL) {
      return NULL;
    }
    return $expires - $now;
  }

  /**
   * Returns the more severe of two statuses.
   */
  private function worse(string $current, string $next): string {
    $rank = ['ok' => 0, 'unknown' => 1, 'warning' => 2, 'error' => 3];
    return ($rank[$next] ?? 0) > ($rank[$current] ?? 0) ? $next : $current;
  }

}
