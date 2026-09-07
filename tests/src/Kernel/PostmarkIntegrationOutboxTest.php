<?php

declare(strict_types=1);

namespace Drupal\Tests\postmark_webhooks\Kernel;

use Drupal\Core\Site\Settings;
use Drupal\Core\Lock\LockBackendInterface;
use Drupal\KernelTests\KernelTestBase;
use Drupal\postmark_webhooks\Controller\PostmarkWebhookController;
use Drupal\postmark_webhooks\Integration\IntegrationEvent;
use Drupal\postmark_webhooks\Integration\IntegrationOutbox;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use Symfony\Component\HttpFoundation\Request;

/**
 * Verifies transactional outbox enqueue, dispatch, replay and privacy.
 *
 * @group postmark_webhooks
 */
#[RunTestsInSeparateProcesses]
class PostmarkIntegrationOutboxTest extends KernelTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'system',
    'postmark_webhooks',
    'postmark_integration_test',
  ];

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->installSchema('postmark_webhooks', [
      'postmark_events',
      'postmark_suppression',
      'postmark_intake_metrics',
      'postmark_integration_outbox',
    ]);
    $this->installConfig(['postmark_webhooks']);
    new Settings(['postmark_webhooks.webhook_secret' => 's3cr3t-webhook-pass']);
    $this->config('postmark_webhooks.settings')->set('integration_events_enabled', TRUE)->save();
  }

  /**
   * Posts one bounce webhook.
   */
  private function bounce(string $id, string $email = 'recipient0@example.com'): void {
    $body = json_encode([
      'RecordType' => 'Bounce',
      'Type' => 'HardBounce',
      'Email' => $email,
      'ID' => $id,
      'ServerID' => 1,
      'MessageStream' => 'outbound',
      'MessageID' => 'msg-' . $id,
      'BouncedAt' => '2020-01-01T12:00:00Z',
    ], JSON_THROW_ON_ERROR);
    $request = Request::create('/api/webhooks/postmark', 'POST', [], [], [], [], $body);
    $request->headers->set('Authorization', 'Basic ' . base64_encode('postmark:s3cr3t-webhook-pass'));
    $response = PostmarkWebhookController::create($this->container)->receive($request);
    $this->assertSame(200, $response->getStatusCode());
  }

  /**
   * Disabled outbox stores no rows.
   */
  public function testDisabledStoresNothing(): void {
    $this->config('postmark_webhooks.settings')->set('integration_events_enabled', FALSE)->save();
    $outbox = $this->container->get('postmark_webhooks.integration_outbox');
    $this->assertFalse($outbox->shouldEnqueue());
    $this->bounce('1');
    $this->assertSame([], $outbox->inspect());
  }

  /**
   * Accepted unique webhooks enqueue; duplicates do not.
   */
  public function testDuplicateWebhookCreatesNoAdditionalNotification(): void {
    $this->bounce('1');
    $this->bounce('1');
    $rows = $this->container->get('postmark_webhooks.integration_outbox')->inspect();
    $types = array_column($rows, 'type');
    sort($types);
    $this->assertSame(['suppression_changed', 'webhook_accepted'], $types);
    $this->assertSame(1, (int) $this->container->get('database')->select('postmark_events')->countQuery()->execute()->fetchField());
  }

  /**
   * A rolled-back transaction leaves no outbox row.
   */
  public function testRolledBackTransactionCreatesNoNotification(): void {
    $event = new IntegrationEvent(
      IntegrationEvent::WEBHOOK_ACCEPTED,
      str_repeat('a', 64),
      '1',
      'outbound',
      1577880000,
      'provider',
      'recipient0@example.com',
      NULL,
      NULL,
    );
    $database = $this->container->get('database');
    $transaction = $database->startTransaction();
    $this->container->get('postmark_webhooks.integration_outbox')->enqueue($event);
    $transaction->rollBack();
    unset($transaction);
    $this->assertSame([], $this->container->get('postmark_webhooks.integration_outbox')->inspect());
  }

  /**
   * A duplicate enqueue rolls back its savepoint; outer work continues.
   */
  public function testDuplicateEnqueueDoesNotPoisonTransaction(): void {
    $event = new IntegrationEvent(
      IntegrationEvent::WEBHOOK_ACCEPTED,
      str_repeat('e', 64),
      '1',
      'outbound',
      1577880000,
      'provider',
      'recipient0@example.com',
      NULL,
      NULL,
    );
    $database = $this->container->get('database');
    $outbox = $this->container->get('postmark_webhooks.integration_outbox');
    $transaction = $database->startTransaction();
    $outbox->enqueue($event);
    $outbox->enqueue($event);
    $count = (int) $database->select('postmark_integration_outbox')->countQuery()->execute()->fetchField();
    unset($transaction);
    $this->assertSame(1, $count);
  }

  /**
   * Pending rows survive until dispatch; inspect omits recipients.
   */
  public function testCrashBetweenCommitAndDispatchThenDelivers(): void {
    $this->bounce('2');
    $pending = $this->container->get('postmark_webhooks.integration_outbox')->inspect();
    $this->assertNotEmpty($pending);
    $this->assertStringNotContainsString('example.com', json_encode($pending));
    $this->assertSame([], $this->container->get('state')->get('postmark_integration_test.events', []));
    $result = $this->container->get('postmark_webhooks.integration_outbox')->dispatch();
    $this->assertSame(2, $result['delivered']);
    $delivered = $this->container->get('state')->get('postmark_integration_test.events');
    $this->assertCount(2, $delivered);
    $this->assertSame('recipient0@example.com', $delivered[0]['recipient']);
  }

  /**
   * Subscriber failure retries and does not undo suppression.
   */
  public function testSubscriberFailureDoesNotUndoSuppression(): void {
    $this->container->get('state')->set('postmark_integration_test.throw', TRUE);
    $this->bounce('3');
    $this->container->get('postmark_webhooks.integration_outbox')->dispatch();
    $this->assertTrue($this->container->get('postmark_webhooks.suppression_policy')->decide('recipient0@example.com')->suppressed);
    $row = $this->container->get('postmark_webhooks.integration_outbox')->inspect()[0];
    $this->assertSame('pending', $row['status']);
    $this->assertSame(1, $row['attempts']);
    $this->assertStringNotContainsString('example.com', $row['last_error']);
    $this->assertStringContainsString('@state', $row['last_error']);
    $this->assertStringContainsString('[redacted]', $row['last_error']);
  }

  /**
   * Replay requeues a failed row for another attempt.
   */
  public function testReplayAndDuplicateWorkerLock(): void {
    $this->config('postmark_webhooks.settings')->set('integration_events_max_attempts', 1)->save();
    $this->container->get('state')->set('postmark_integration_test.throw', TRUE);
    $this->bounce('4');
    $this->container->get('postmark_webhooks.integration_outbox')->dispatch();
    $failed = $this->container->get('postmark_webhooks.integration_outbox')->inspect();
    $this->assertSame('failed', $failed[0]['status']);
    $this->container->get('state')->set('postmark_integration_test.throw', FALSE);
    $this->container->get('postmark_webhooks.integration_outbox')->replay((int) $failed[0]['oid']);
    $this->container->get('postmark_webhooks.integration_outbox')->dispatch();
    $this->assertNotEmpty($this->container->get('state')->get('postmark_integration_test.events', []));
    $lock = $this->createMock(LockBackendInterface::class);
    $lock->method('acquire')->willReturn(FALSE);
    $outbox = new IntegrationOutbox(
      $this->container->get('database'),
      $this->container->get('config.factory'),
      $this->container->get('module_handler'),
      $lock,
      $this->container->get('logger.factory'),
      $this->container->get('datetime.time'),
    );
    $skipped = $outbox->dispatch();
    $this->assertSame('lock', $skipped['skipped']);
  }

  /**
   * Unsupported versions, invalid JSON, and incomplete payloads fail once.
   */
  public function testPoisonPayloadFailsWithoutRetry(): void {
    $now = $this->container->get('datetime.time')->getCurrentTime();
    $database = $this->container->get('database');
    $database->insert('postmark_integration_outbox')->fields([
      'fingerprint' => str_repeat('b', 64),
      'type' => IntegrationEvent::WEBHOOK_ACCEPTED,
      'version' => 99,
      'payload' => json_encode([
        'type' => IntegrationEvent::WEBHOOK_ACCEPTED,
        'version' => 99,
        'eventKey' => str_repeat('c', 64),
        'source' => ['serverId' => '1', 'messageStream' => 'outbound'],
        'occurred' => $now,
        'timeBasis' => 'provider',
        'recipient' => 'recipient0@example.com',
        'reason' => NULL,
        'suppressed' => NULL,
      ], JSON_THROW_ON_ERROR),
      'status' => 'pending',
      'attempts' => 0,
      'available_at' => $now,
      'created' => $now,
      'delivered_at' => 0,
      'last_error' => '',
    ])->execute();
    $database->insert('postmark_integration_outbox')->fields([
      'fingerprint' => str_repeat('d', 64),
      'type' => IntegrationEvent::WEBHOOK_ACCEPTED,
      'version' => 1,
      'payload' => '{',
      'status' => 'pending',
      'attempts' => 0,
      'available_at' => $now,
      'created' => $now,
      'delivered_at' => 0,
      'last_error' => '',
    ])->execute();
    $database->insert('postmark_integration_outbox')->fields([
      'fingerprint' => str_repeat('e', 64),
      'type' => IntegrationEvent::WEBHOOK_ACCEPTED,
      'version' => 1,
      'payload' => json_encode([
        'type' => IntegrationEvent::WEBHOOK_ACCEPTED,
        'version' => 1,
        'eventKey' => str_repeat('f', 64),
        'source' => ['serverId' => '1', 'messageStream' => 'outbound'],
        'occurred' => $now,
        'timeBasis' => 'provider',
      ], JSON_THROW_ON_ERROR),
      'status' => 'pending',
      'attempts' => 0,
      'available_at' => $now,
      'created' => $now,
      'delivered_at' => 0,
      'last_error' => '',
    ])->execute();
    $result = $this->container->get('postmark_webhooks.integration_outbox')->dispatch();
    $this->assertSame(3, $result['failed']);
    $this->assertSame(0, $result['delivered']);
    foreach ($this->container->get('postmark_webhooks.integration_outbox')->inspect() as $row) {
      $this->assertSame('failed', $row['status']);
      $this->assertSame(1, $row['attempts']);
      $this->assertStringNotContainsString('example.com', json_encode($row));
    }
    $again = $this->container->get('postmark_webhooks.integration_outbox')->dispatch();
    $this->assertSame(0, $again['attempted']);
  }

}
