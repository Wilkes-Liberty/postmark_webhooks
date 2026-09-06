<?php

declare(strict_types=1);

namespace Drupal\Tests\postmark_webhooks_reconcile\Kernel;

use Drupal\Core\Extension\ModuleHandlerInterface;
use Drupal\Core\Lock\LockBackendInterface;
use Drupal\Core\Site\Settings;
use Drupal\KernelTests\KernelTestBase;
use Drupal\postmark_webhooks_reconcile\ProviderReader;
use Drupal\postmark_webhooks_reconcile\Reconciliation;
use Drupal\postmark_webhooks_reconcile\ScheduledReconciliation;
use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Verifies opt-in scheduled drift previews without applying imports.
 *
 * @group postmark_webhooks
 */
#[RunTestsInSeparateProcesses]
class ScheduledReconciliationTest extends KernelTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'system',
    'postmark_webhooks',
    'postmark_webhooks_reconcile',
  ];

  /**
   * Captured drift-hook payloads.
   *
   * @var array<int, array<string, mixed>>
   */
  private array $driftReports = [];

  /**
   * Whether the next drift hook should fail.
   */
  private bool $driftThrows = FALSE;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->installSchema('postmark_webhooks', ['postmark_suppression']);
    $this->installSchema('postmark_webhooks_reconcile', [
      'postmark_reconciliation',
      'postmark_drift_report',
    ]);
    $this->installConfig(['postmark_webhooks', 'postmark_webhooks_reconcile']);
    new Settings(['postmark_webhooks.reconciliation_tokens' => ['1' => 'never-disclose-token']]);
  }

  /**
   * Creates fake provider responses for one dump fetch.
   */
  private function responses(string $date, int $count = 1, string $reason = 'HardBounce'): array {
    $rows = [];
    for ($i = 0; $i < $count; $i++) {
      $rows[] = [
        'EmailAddress' => 'recipient' . $i . '@example.com',
        'SuppressionReason' => $reason,
        'Origin' => 'Recipient',
        'CreatedAt' => $date . 'T12:00:00Z',
      ];
    }
    return [
      new Response(200, [], json_encode(['ID' => 1, 'ApiTokens' => ['another-secret']])),
      new Response(200, [], json_encode(['Suppressions' => $rows])),
    ];
  }

  /**
   * Builds a scheduler with only a fake HTTP transport.
   */
  private function scheduler(array $responses, array &$history, ?LockBackendInterface $lock = NULL): ScheduledReconciliation {
    $handler = HandlerStack::create(new MockHandler($responses));
    $handler->push(Middleware::history($history));
    $reader = new ProviderReader(new Client(['handler' => $handler]), $this->container->get('datetime.time'));
    $reconciliation = new Reconciliation(
      $this->container->get('database'),
      $reader,
      $this->container->get('postmark_webhooks.suppression_store'),
      $this->container->get('datetime.time'),
    );
    $modules = $this->createMock(ModuleHandlerInterface::class);
    $modules->method('invokeAll')->willReturnCallback(function (string $hook, array $args = []) {
      if ($hook !== 'postmark_webhooks_drift_report') {
        return [];
      }
      if ($this->driftThrows) {
        throw new \RuntimeException('Synthetic drift notification failure.');
      }
      $this->driftReports[] = $args[0];
      return [];
    });
    return new ScheduledReconciliation(
      $reconciliation,
      $this->container->get('database'),
      $this->container->get('config.factory'),
      $this->container->get('state'),
      $lock ?? $this->container->get('lock'),
      $modules,
      $this->container->get('logger.factory'),
      $this->container->get('datetime.time'),
    );
  }

  /**
   * Enables a one-source schedule ending yesterday.
   */
  private function enableSchedule(int $lookback = 1, int $per_run = 1, int $interval = 86400): void {
    $this->config('postmark_webhooks_reconcile.settings')
      ->set('enabled', TRUE)
      ->set('lookback_days', $lookback)
      ->set('dates_per_run', $per_run)
      ->set('interval_seconds', $interval)
      ->set('report_retention_seconds', 604800)
      ->set('alert_cooldown_seconds', 3600)
      ->set('sources', [['server_id' => '1', 'message_stream' => 'outbound']])
      ->save();
  }

  /**
   * Completed UTC date used by the scheduler window.
   */
  private function utcDate(int $days_ago): string {
    return gmdate('Y-m-d', $this->container->get('datetime.time')->getCurrentTime() - ($days_ago * 86400));
  }

  /**
   * Disabled schedule makes no provider request.
   */
  public function testDisabledByDefault(): void {
    $history = [];
    $scheduler = $this->scheduler([], $history);
    $result = $scheduler->run();
    $this->assertFalse($result['ran']);
    $this->assertSame('disabled', $result['reason']);
    $this->assertSame([], $history);
  }

  /**
   * A complete scan stores counts, applies nothing, and omits mailbox labels.
   */
  public function testScanWritesReportWithoutApplying(): void {
    $this->enableSchedule();
    $date = $this->utcDate(1);
    $history = [];
    $scheduler = $this->scheduler($this->responses($date), $history);
    $now = $this->container->get('datetime.time')->getCurrentTime();
    $result = $scheduler->run($now);
    $database = $this->container->get('database');
    $this->assertTrue($result['ran']);
    $this->assertFalse($result['local_suppression_changed']);
    $this->assertFalse($result['provider_writes']);
    $this->assertSame(0, (int) $database->select('postmark_suppression')->countQuery()->execute()->fetchField());
    $this->assertSame(0, (int) $database->select('postmark_reconciliation')->countQuery()->execute()->fetchField());
    $reports = $scheduler->reports();
    $this->assertCount(1, $reports);
    $this->assertSame('complete', $reports[0]['status']);
    $this->assertSame($date, $reports[0]['source_date']);
    $this->assertSame(1, $reports[0]['differences']['new_evidence']);
    $this->assertFalse($reports[0]['absence_clears_local']);
    $encoded = json_encode($reports);
    $this->assertStringNotContainsString('example.com', $encoded);
    $this->assertStringNotContainsString('never-disclose-token', $encoded);
    $this->assertStringNotContainsString('another-secret', $encoded);
    $this->assertCount(1, $this->driftReports);
    $this->assertStringNotContainsString('example.com', json_encode($this->driftReports));
    foreach ($history as $request) {
      $this->assertSame('GET', $request['request']->getMethod());
      $this->assertSame('api.postmarkapp.com', $request['request']->getUri()->getHost());
    }
  }

  /**
   * Date cursors resume after an interrupted window.
   */
  public function testRestartResumesCursor(): void {
    $this->enableSchedule(2, 1, 0);
    $older = $this->utcDate(2);
    $newer = $this->utcDate(1);
    $history = [];
    $scheduler = $this->scheduler(array_merge($this->responses($older), $this->responses($newer)), $history);
    $now = $this->container->get('datetime.time')->getCurrentTime();
    $first = $scheduler->run($now);
    $this->assertSame($older, $first['sources'][0]['scanned'][0]['source_date']);
    $this->assertCount(1, $scheduler->reports());
    $second = $scheduler->run($now);
    $this->assertSame($newer, $second['sources'][0]['scanned'][0]['source_date']);
    $this->assertCount(2, $scheduler->reports());
  }

  /**
   * A held per-source lock prevents a duplicate overlapping run.
   */
  public function testLockPreventsOverlap(): void {
    $this->enableSchedule();
    $history = [];
    $lock = $this->createMock(LockBackendInterface::class);
    $lock->expects($this->once())->method('acquire')->willReturn(FALSE);
    $scheduler = $this->scheduler($this->responses($this->utcDate(1)), $history, $lock);
    $result = $scheduler->run();
    $this->assertSame('lock', $result['sources'][0]['skipped']);
    $this->assertSame([], $history);
  }

  /**
   * Rate limits are incomplete, not an empty provider day, and do not apply.
   */
  public function testRateLimitIsIncompleteNotEmpty(): void {
    $this->enableSchedule();
    $history = [];
    $scheduler = $this->scheduler([
      new Response(200, [], '{"ID":1}'),
      new Response(429, ['Retry-After' => '90'], 'never-disclose-token'),
    ], $history);
    $now = $this->container->get('datetime.time')->getCurrentTime();
    $scheduler->run($now);
    $report = $scheduler->reports()[0];
    $this->assertSame('incomplete', $report['status']);
    $this->assertSame('rate_limit', $report['incomplete_reason']);
    $this->assertNotSame('empty', $report['status']);
    $this->assertStringNotContainsString('never-disclose-token', json_encode($report));
    $this->assertSame(0, (int) $this->container->get('database')->select('postmark_suppression')->countQuery()->execute()->fetchField());
    $again = $scheduler->run($now);
    $this->assertSame('retry', $again['sources'][0]['skipped']);
  }

  /**
   * An empty dump is a completed empty result, not an incomplete scan.
   */
  public function testEmptyDumpIsNotIncomplete(): void {
    $this->enableSchedule();
    $history = [];
    $scheduler = $this->scheduler([
      new Response(200, [], '{"ID":1}'),
      new Response(200, [], '{"Suppressions":[]}'),
    ], $history);
    $scheduler->run($this->container->get('datetime.time')->getCurrentTime());
    $report = $scheduler->reports()[0];
    $this->assertSame('empty', $report['status']);
    $this->assertSame('', $report['incomplete_reason']);
    $this->assertSame(0, $report['provider_total']);
  }

  /**
   * Oversized dumps are incomplete and do not loop on the same date.
   */
  public function testOversizedIsIncompleteAndAdvances(): void {
    $this->enableSchedule();
    $history = [];
    $scheduler = $this->scheduler([
      new Response(200, [], '{"ID":1}'),
      new Response(200, [], str_repeat('x', ProviderReader::MAX_BYTES + 1)),
      new Response(200, [], '{"ID":1}'),
      new Response(200, [], '{"Suppressions":[]}'),
    ], $history);
    $now = $this->container->get('datetime.time')->getCurrentTime();
    $scheduler->run($now);
    $this->assertSame('oversized', $scheduler->reports()[0]['incomplete_reason']);
    $second = $scheduler->run($now);
    $this->assertSame('interval', $second['sources'][0]['skipped']);
  }

  /**
   * Missing tokens are incomplete credentials without a provider request.
   */
  public function testExpiredCredentials(): void {
    new Settings(['postmark_webhooks.reconciliation_tokens' => []]);
    $this->enableSchedule();
    $history = [];
    $scheduler = $this->scheduler([], $history);
    $scheduler->run($this->container->get('datetime.time')->getCurrentTime());
    $report = $scheduler->reports()[0];
    $this->assertSame('incomplete', $report['status']);
    $this->assertSame('credentials', $report['incomplete_reason']);
    $this->assertSame([], $history);
  }

  /**
   * Provider absence does not clear local consent, complaint or unsubscribe.
   */
  public function testConcurrentWebhookDoesNotInferConsentFromAbsence(): void {
    $store = $this->container->get('postmark_webhooks.suppression_store');
    $store->record([
      'event_type' => 'SubscriptionChange',
      'suppress_sending' => 1,
      'suppression_reason' => 'ManualSuppression',
      'recipient' => 'kept@example.com',
      'server_id' => '1',
      'message_stream' => 'outbound',
      'created' => 1700000000,
      'event_key' => str_repeat('d', 64),
    ]);
    $this->enableSchedule();
    $date = $this->utcDate(1);
    $history = [];
    $scheduler = $this->scheduler($this->responses($date), $history);
    $now = $this->container->get('datetime.time')->getCurrentTime();
    $scheduler->run($now);
    $store->record([
      'event_type' => 'Bounce',
      'bounce_type' => 'HardBounce',
      'recipient' => 'recipient0@example.com',
      'server_id' => '1',
      'message_stream' => 'outbound',
      'created' => $now,
      'occurred' => $now,
      'time_basis' => 'receipt',
      'event_key' => str_repeat('e', 64),
    ]);
    $policy = $this->container->get('postmark_webhooks.suppression_policy');
    $this->assertTrue($policy->decide('kept@example.com')->suppressed);
    $this->assertSame('consent:ManualSuppression', $policy->decide('kept@example.com')->reason);
    $this->assertTrue($policy->decide('recipient0@example.com')->suppressed);
    $report = $scheduler->reports()[0];
    $this->assertFalse($report['absence_clears_local']);
    $this->assertSame(1, $report['differences']['new_evidence']);
    $this->assertStringNotContainsString('kept@', json_encode($report));
  }

  /**
   * Expired reports are deleted in bounded batches; suppression is retained.
   */
  public function testReportRetention(): void {
    $this->enableSchedule();
    $this->config('postmark_webhooks_reconcile.settings')->set('report_retention_seconds', 60)->save();
    $history = [];
    $scheduler = $this->scheduler($this->responses($this->utcDate(1)), $history);
    $now = $this->container->get('datetime.time')->getCurrentTime();
    $scheduler->run($now);
    $this->container->get('database')->update('postmark_drift_report')
      ->fields(['created' => $now - 120])
      ->execute();
    $this->container->get('postmark_webhooks.suppression_store')->record([
      'event_type' => 'Bounce',
      'bounce_type' => 'HardBounce',
      'recipient' => 'kept@example.com',
      'server_id' => '1',
      'message_stream' => 'outbound',
      'created' => $now,
      'event_key' => str_repeat('f', 64),
    ]);
    $scheduler->prune($now);
    $this->assertSame([], $scheduler->reports());
    $this->assertSame(1, (int) $this->container->get('database')->select('postmark_suppression')->countQuery()->execute()->fetchField());
  }

  /**
   * Notification delivery failure does not fail the stored report.
   */
  public function testNotificationFailureDoesNotFailScan(): void {
    $this->enableSchedule();
    $this->driftThrows = TRUE;
    $history = [];
    $scheduler = $this->scheduler($this->responses($this->utcDate(1)), $history);
    $scheduler->run($this->container->get('datetime.time')->getCurrentTime());
    $this->assertSame('complete', $scheduler->reports()[0]['status']);
    $this->assertSame(0, (int) $this->container->get('database')->select('postmark_suppression')->countQuery()->execute()->fetchField());
  }

}
