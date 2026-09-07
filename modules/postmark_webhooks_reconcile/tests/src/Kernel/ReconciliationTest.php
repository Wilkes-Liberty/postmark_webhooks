<?php

declare(strict_types=1);

namespace Drupal\Tests\postmark_webhooks_reconcile\Kernel;

use Drupal\Core\Site\Settings;
use Drupal\Core\Database\DatabaseExceptionWrapper;
use Drupal\KernelTests\KernelTestBase;
use Drupal\postmark_webhooks\Source\SourceContext;
use Drupal\postmark_webhooks_reconcile\ProviderReadException;
use Drupal\postmark_webhooks_reconcile\ProviderReader;
use Drupal\postmark_webhooks_reconcile\Reconciliation;
use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Verifies reviewed, bounded imports against fake provider HTTP responses.
 *
 * @group postmark_webhooks
 */
#[RunTestsInSeparateProcesses]
class ReconciliationTest extends KernelTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = ['system', 'postmark_webhooks', 'postmark_webhooks_reconcile'];

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->installSchema('postmark_webhooks', ['postmark_suppression']);
    $this->installSchema('postmark_webhooks_reconcile', ['postmark_reconciliation']);
    $this->installConfig(['postmark_webhooks']);
    new Settings(['postmark_webhooks.reconciliation_tokens' => ['1' => 'never-disclose-token']]);
  }

  /**
   * Creates fake provider responses for one full dump fetch.
   */
  private function responses(int $count = 1, string $reason = 'HardBounce'): array {
    $rows = [];
    for ($i = 0; $i < $count; $i++) {
      $rows[] = [
        'EmailAddress' => 'recipient' . $i . '@example.com',
        'SuppressionReason' => $reason,
        'Origin' => 'Recipient',
        'CreatedAt' => '2020-01-01T12:00:00Z',
      ];
    }
    return [
      new Response(200, [], json_encode(['ID' => 1, 'ApiTokens' => ['another-secret']])),
      new Response(200, [], json_encode(['Suppressions' => $rows])),
    ];
  }

  /**
   * Builds a real reader/reconciler with only a fake HTTP transport.
   */
  private function reconciliation(array $responses, array &$history): Reconciliation {
    $handler = HandlerStack::create(new MockHandler($responses));
    $handler->push(Middleware::history($history));
    $reader = new ProviderReader(new Client(['handler' => $handler]), $this->container->get('datetime.time'));
    return new Reconciliation($this->container->get('database'), $reader, $this->container->get('postmark_webhooks.suppression_store'), $this->container->get('datetime.time'));
  }

  /**
   * Preview is non-mutating; local pages and checkpoints resume atomically.
   */
  public function testReviewAndResume(): void {
    $history = [];
    $reconciliation = $this->reconciliation(array_merge($this->responses(251), $this->responses(251), $this->responses(251)), $history);
    $plan = $reconciliation->preview(new SourceContext('1', 'outbound'), '2020-01-01');
    $database = $this->container->get('database');
    $this->assertSame(0, (int) $database->select('postmark_suppression')->countQuery()->execute()->fetchField());
    $this->assertSame(251, $plan['differences']['new_evidence']);
    $this->assertStringNotContainsString('example.com', json_encode($plan));
    $this->assertStringNotContainsString('secret', json_encode($plan));
    $first = $reconciliation->apply($plan['job_id']);
    $this->assertSame(250, $first['processed']);
    $this->assertSame('applying', $first['status']);
    $this->assertSame(250, (int) $database->select('postmark_suppression')->countQuery()->execute()->fetchField());
    $last = $reconciliation->apply($plan['job_id']);
    $this->assertSame(251, $last['processed']);
    $this->assertSame('complete', $last['status']);
    $this->assertSame($last, $reconciliation->apply($plan['job_id']), 'Completed retries need no provider request.');
    foreach ($history as $request) {
      $this->assertSame('GET', $request['request']->getMethod());
      $this->assertSame('api.postmarkapp.com', $request['request']->getUri()->getHost());
      $this->assertFalse($request['options']['allow_redirects']);
      $this->assertTrue($request['options']['verify']);
    }
  }

  /**
   * Changed provider content cannot be substituted after review.
   */
  public function testChangedDumpRequiresReview(): void {
    $history = [];
    $reconciliation = $this->reconciliation(array_merge($this->responses(), $this->responses(2)), $history);
    $plan = $reconciliation->preview(new SourceContext('1', 'outbound'), '2020-01-01');
    $this->expectException(\InvalidArgumentException::class);
    try {
      $reconciliation->apply($plan['job_id']);
    }
    finally {
      $this->assertSame(0, $reconciliation->status($plan['job_id'])['processed']);
    }
  }

  /**
   * Preview mirrors the store's deterministic evidence ordering at equal times.
   */
  public function testEqualTimestampEvidencePreview(): void {
    $this->container->get('postmark_webhooks.suppression_store')->record([
      'event_type' => 'Bounce',
      'bounce_type' => 'HardBounce',
      'recipient' => 'recipient0@example.com',
      'server_id' => '1',
      'message_stream' => 'outbound',
      'created' => 1577880000,
      'event_key' => str_repeat('0', 64),
    ]);
    $history = [];
    $reconciliation = $this->reconciliation(array_merge($this->responses(), $this->responses()), $history);
    $plan = $reconciliation->preview(new SourceContext('1', 'outbound'), '2020-01-01');
    $this->assertSame(1, $plan['differences']['newer_evidence']);
    $this->assertSame(0, $plan['differences']['unchanged_or_older']);
    $reconciliation->apply($plan['job_id']);
    $evidence = $this->container->get('database')->select('postmark_suppression', 's')->fields('s', ['evidence'])->execute()->fetchField();
    $this->assertNotSame(str_repeat('0', 64), $evidence);
  }

  /**
   * Rate limits preserve progress and expose only sanitized retry guidance.
   */
  public function testRateLimitPreservesCheckpoint(): void {
    $history = [];
    $responses = array_merge($this->responses(), [new Response(429, ['Retry-After' => '120'], 'never-disclose-token')], $this->responses());
    $reconciliation = $this->reconciliation($responses, $history);
    $plan = $reconciliation->preview(new SourceContext('1', 'outbound'), '2020-01-01');
    try {
      $reconciliation->apply($plan['job_id']);
      $this->fail('Expected a rate limit.');
    }
    catch (ProviderReadException $exception) {
      $this->assertSame(120, $exception->retryAfter);
      $this->assertStringNotContainsString('never-disclose-token', (string) $exception);
    }
    $this->assertSame(0, $reconciliation->status($plan['job_id'])['processed']);
    $this->assertSame('complete', $reconciliation->apply($plan['job_id'])['status']);
  }

  /**
   * Stale hard bounces never override newer releases or clear consent.
   */
  public function testStaleEvidenceAndConsent(): void {
    $store = $this->container->get('postmark_webhooks.suppression_store');
    $store->record([
      'event_type' => 'SubscriptionChange',
      'suppress_sending' => 0,
      'recipient' => 'recipient0@example.com',
      'server_id' => '1',
      'message_stream' => 'outbound',
      'created' => 1700000000,
      'event_key' => str_repeat('b', 64),
    ]);
    $history = [];
    $reconciliation = $this->reconciliation(array_merge($this->responses(), $this->responses()), $history);
    $plan = $reconciliation->preview(new SourceContext('1', 'outbound'), '2020-01-01');
    $reconciliation->apply($plan['job_id']);
    $this->assertFalse($this->container->get('postmark_webhooks.suppression_policy')->decide('recipient0@example.com')->suppressed);
    $store->record([
      'event_type' => 'SubscriptionChange',
      'suppress_sending' => 1,
      'suppression_reason' => 'ManualSuppression',
      'recipient' => 'recipient0@example.com',
      'server_id' => '1',
      'message_stream' => 'outbound',
      'created' => 10,
      'event_key' => str_repeat('c', 64),
    ]);
    $reconciliation->apply($plan['job_id']);
    $this->assertSame('consent:ManualSuppression', $this->container->get('postmark_webhooks.suppression_policy')->decide('recipient0@example.com')->reason);
  }

  /**
   * A token cannot import evidence into a different server's source.
   */
  public function testWrongTokenServer(): void {
    $history = [];
    $reconciliation = $this->reconciliation([new Response(200, [], '{"ID":2}')], $history);
    $this->expectException(ProviderReadException::class);
    $reconciliation->preview(new SourceContext('1', 'outbound'), '2020-01-01');
  }

  /**
   * Settings-bound source restrictions apply without any provider request.
   */
  public function testSourceRestriction(): void {
    new Settings([
      'postmark_webhooks.reconciliation_tokens' => ['1' => 'never-disclose-token'],
      'postmark_webhooks.allowed_sources' => [],
    ]);
    $history = [];
    $reconciliation = $this->reconciliation([], $history);
    $this->expectException(ProviderReadException::class);
    try {
      $reconciliation->preview(new SourceContext('1', 'outbound'), '2020-01-01');
    }
    finally {
      $this->assertSame([], $history);
    }
  }

  /**
   * Oversized provider bodies are refused before creating any review record.
   */
  public function testBoundedProviderBody(): void {
    $history = [];
    $reconciliation = $this->reconciliation([
      new Response(200, [], '{"ID":1}'),
      new Response(200, [], str_repeat('x', ProviderReader::MAX_BYTES + 1)),
    ], $history);
    $this->expectException(ProviderReadException::class);
    $reconciliation->preview(new SourceContext('1', 'outbound'), '2020-01-01');
  }

  /**
   * Persistence failure cannot advance a local import checkpoint.
   */
  public function testFailedPagePreservesCheckpoint(): void {
    $history = [];
    $reconciliation = $this->reconciliation(array_merge($this->responses(), $this->responses()), $history);
    $plan = $reconciliation->preview(new SourceContext('1', 'outbound'), '2020-01-01');
    $this->container->get('database')->schema()->dropTable('postmark_suppression');
    $this->expectException(DatabaseExceptionWrapper::class);
    try {
      $reconciliation->apply($plan['job_id']);
    }
    finally {
      $this->assertSame(0, $reconciliation->status($plan['job_id'])['processed']);
    }
  }

  /**
   * Existing reconciliation sites get drift-report storage without the
   * removed helper.
   */
  public function testUpdateCreatesDriftReportTable(): void {
    $schema = $this->container->get('database')->schema();
    $this->assertFalse($schema->tableExists('postmark_drift_report'));
    $this->container->get('module_handler')
      ->loadInclude('postmark_webhooks_reconcile', 'install');
    postmark_webhooks_reconcile_update_10001();
    $this->assertTrue($schema->tableExists('postmark_drift_report'));
    postmark_webhooks_reconcile_update_10001();
    $this->assertTrue($schema->tableExists('postmark_drift_report'));
  }

}
