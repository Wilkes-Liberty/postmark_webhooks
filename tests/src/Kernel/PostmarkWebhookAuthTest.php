<?php

declare(strict_types=1);

namespace Drupal\Tests\postmark_webhooks\Kernel;

use Drupal\Core\Site\Settings;
use Drupal\Core\Config\ConfigEvents;
use Drupal\Core\Config\ConfigImporter;
use Drupal\Core\Config\ConfigImporterEvent;
use Drupal\Core\Config\MemoryStorage;
use Drupal\Core\Config\StorageComparer;
use Drupal\Core\Database\IntegrityConstraintViolationException;
use Drupal\Core\Database\DatabaseExceptionWrapper;
use Drupal\KernelTests\KernelTestBase;
use Drupal\Component\Datetime\TimeInterface;
use Drupal\postmark_webhooks\Controller\PostmarkWebhookController;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\EventDispatcher\EventDispatcher;

/**
 * Kernel tests for the Postmark webhook HTTP Basic Auth controller.
 *
 * Verifies that PostmarkWebhookController::receive() authenticates via Basic
 * Auth (secret = password, sent in the Authorization header — never in the URL)
 * and only records an event for an authenticated, well-formed request.
 *
 * @group postmark_webhooks
 */
#[RunTestsInSeparateProcesses]
class PostmarkWebhookAuthTest extends KernelTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = ['postmark_webhooks'];

  private const SECRET = 's3cr3t-webhook-pass';

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->installSchema('postmark_webhooks', ['postmark_events', 'postmark_suppression']);
    $this->installConfig(['postmark_webhooks']);
  }

  /**
   * Sets (or clears) the configured webhook secret.
   */
  private function setSecret(string $secret): void {
    new Settings(['postmark_webhooks.webhook_secret' => $secret] + Settings::getAll());
  }

  /**
   * Builds a POST request, optionally with a Basic Auth password.
   */
  private function request(?string $password, string $body = '{"RecordType":"Bounce","Type":"HardBounce","Email":"x@example.com","MessageID":"msg-1"}'): Request {
    $request = Request::create('/api/webhooks/postmark', 'POST', [], [], [], [], $body);
    if ($password !== NULL) {
      $request->headers->set('Authorization', 'Basic ' . base64_encode('postmark:' . $password));
    }
    return $request;
  }

  /**
   * Invokes the controller against the current container.
   */
  private function receive(Request $request) {
    $controller = PostmarkWebhookController::create($this->container);
    return $controller->receive($request);
  }

  /**
   * Counts rows in postmark_events.
   */
  private function eventCount(): int {
    return (int) \Drupal::database()->select('postmark_events')->countQuery()->execute()->fetchField();
  }

  /**
   * A request with the correct secret is accepted and records the event.
   */
  public function testValidSecretRecordsEvent(): void {
    $this->setSecret(self::SECRET);
    $response = $this->receive($this->request(self::SECRET));
    $this->assertSame(200, $response->getStatusCode());
    $this->assertSame(1, $this->eventCount(), 'A valid webhook records exactly one event.');
  }

  /**
   * A request with no Authorization header is rejected with 401.
   */
  public function testMissingAuthRejected(): void {
    $this->setSecret(self::SECRET);
    $response = $this->receive($this->request(NULL));
    $this->assertSame(401, $response->getStatusCode());
    $this->assertTrue($response->headers->has('WWW-Authenticate'));
    $this->assertSame(0, $this->eventCount(), 'An unauthenticated request records nothing.');
  }

  /**
   * A request with the wrong secret is rejected with 401.
   */
  public function testWrongSecretRejected(): void {
    $this->setSecret(self::SECRET);
    $response = $this->receive($this->request('wrong-password'));
    $this->assertSame(401, $response->getStatusCode());
    $this->assertSame(0, $this->eventCount());
  }

  /**
   * When no secret is configured the endpoint refuses all posts (503).
   */
  public function testUnconfiguredSecretRefuses(): void {
    // No settings.php secret has been configured.
    $response = $this->receive($this->request(self::SECRET));
    $this->assertSame(503, $response->getStatusCode());
    $this->assertSame(0, $this->eventCount());
  }

  /**
   * An explicitly empty settings secret cannot fall back to exported config.
   */
  public function testEmptySecretIgnoresConfig(): void {
    $this->setSecret('');
    $name = 'postmark_webhooks.settings';
    $storage = $this->container->get('config.storage');
    $storage->write($name, ['webhook_secret' => self::SECRET] + $storage->read($name));
    $this->container->get('config.factory')->reset($name);
    $response = $this->receive($this->request(self::SECRET));
    $this->assertSame(503, $response->getStatusCode());
    $this->assertSame(0, $this->eventCount());
  }

  /**
   * Installation does not create a configuration secret.
   */
  public function testNoSecretInInstallConfig(): void {
    $this->assertArrayNotHasKey('webhook_secret', $this->config('postmark_webhooks.settings')->getRawData());
  }

  /**
   * An authenticated request with a non-JSON body is a 400 and records nothing.
   */
  public function testAuthenticatedButMalformedBodyRejected(): void {
    $this->setSecret(self::SECRET);
    $response = $this->receive($this->request(self::SECRET, 'not-json'));
    $this->assertSame(400, $response->getStatusCode());
    $this->assertSame(0, $this->eventCount());
  }

  /**
   * A repeated MessageID is acknowledged without a second row.
   */
  public function testDuplicateMessageIdIsIdempotent(): void {
    $this->setSecret(self::SECRET);
    $body = '{"RecordType":"Bounce","Type":"HardBounce","Email":"x@example.com","MessageID":"dup-1"}';
    $this->assertSame(200, $this->receive($this->request(self::SECRET, $body))->getStatusCode());
    $this->assertSame(200, $this->receive($this->request(self::SECRET, $body))->getStatusCode());
    $this->assertSame(1, $this->eventCount());
  }

  /**
   * The raw webhook body is not persisted.
   */
  public function testPayloadIsNotStored(): void {
    $this->setSecret(self::SECRET);
    $this->receive($this->request(self::SECRET));
    $payload = \Drupal::database()->select('postmark_events', 'pe')
      ->fields('pe', ['payload'])
      ->range(0, 1)
      ->execute()
      ->fetchField();
    $this->assertTrue($payload === NULL || $payload === '', 'The raw Postmark body must not be stored.');
  }

  /**
   * Different events and recipients of one message must all survive retries.
   */
  public function testEventIdentityPreservesDistinctEvents(): void {
    $this->setSecret(self::SECRET);
    $events = [
      ['RecordType' => 'Delivery', 'Recipient' => 'x@example.com'],
      ['RecordType' => 'SpamComplaint', 'Email' => 'x@example.com', 'ID' => 10],
      ['RecordType' => 'SpamComplaint', 'Email' => 'other@example.com', 'ID' => 10],
      ['RecordType' => 'SpamComplaint', 'Email' => 'x@example.com', 'ID' => 11],
      [
        'RecordType' => 'Bounce',
        'Type' => 'HardBounce',
        'Email' => 'x@example.com',
        'BouncedAt' => '2026-09-01T00:00:00Z',
      ],
      [
        'RecordType' => 'Bounce',
        'Type' => 'HardBounce',
        'Email' => 'x@example.com',
        'BouncedAt' => '2026-09-02T00:00:00Z',
      ],
      ['RecordType' => 'Bounce', 'Type' => 'HardBounce', 'Email' => 'x@example.com', 'ServerID' => 2],
      ['RecordType' => 'Bounce', 'Type' => 'HardBounce', 'Email' => 'x@example.com', 'MessageStream' => 'broadcast'],
    ];
    foreach ($events as $event) {
      $body = json_encode($event + ['MessageID' => 'shared-message']);
      for ($retry = 0; $retry < 2; $retry++) {
        $this->assertSame(200, $this->receive($this->request(self::SECRET, $body))->getStatusCode());
      }
    }
    $this->assertSame(count($events), $this->eventCount());
  }

  /**
   * Identical events without a message ID are still idempotent.
   */
  public function testMissingMessageIdRetries(): void {
    $this->setSecret(self::SECRET);
    $body = json_encode(['RecordType' => 'Bounce', 'Type' => 'HardBounce', 'Email' => 'x@example.com', 'ID' => 42]);
    $this->receive($this->request(self::SECRET, $body));
    $this->receive($this->request(self::SECRET, $body));
    $this->assertSame(1, $this->eventCount());
  }

  /**
   * Other integrity failures are not acknowledged as retries.
   */
  public function testUnrelatedConstraintFailurePropagates(): void {
    $this->setSecret(self::SECRET);
    $this->container->get('database')->schema()->addUniqueKey('postmark_events', 'test_recipient', ['recipient']);
    $this->receive($this->request(self::SECRET));
    $this->expectException(IntegrityConstraintViolationException::class);
    $body = json_encode(['RecordType' => 'Delivery', 'Recipient' => 'x@example.com', 'MessageID' => 'different']);
    $this->receive($this->request(self::SECRET, $body));
  }

  /**
   * The upgrade preserves legacy duplicates and their suppression evidence.
   */
  public function testUpgradePreservesLegacyRows(): void {
    $database = $this->container->get('database');
    $schema = $database->schema();
    $schema->dropUniqueKey('postmark_events', 'event_key');
    $schema->dropField('postmark_events', 'event_key');
    for ($i = 0; $i < 2; $i++) {
      $database->insert('postmark_events')->fields([
        'created' => 1,
        'event_type' => 'SpamComplaint',
        'recipient' => 'x@example.com',
        'message_id' => 'legacy',
      ])->execute();
    }
    \Drupal::moduleHandler()->loadInclude('postmark_webhooks', 'install');
    postmark_webhooks_update_10001();
    postmark_webhooks_update_10001();
    $this->assertSame(2, $this->eventCount());
    $this->assertSame(2, (int) $database->select('postmark_events')->isNull('event_key')->countQuery()->execute()->fetchField());
    $this->setSecret(self::SECRET);
    $this->receive($this->request(self::SECRET));
    $this->receive($this->request(self::SECRET));
    $this->assertSame(3, $this->eventCount());
  }

  /**
   * Independent concurrent receivers commit one event and acknowledge both.
   */
  public function testConcurrentRetries(): void {
    $this->runConcurrentReceivers([123, 123]);
  }

  /**
   * Different events racing to create one suppression record keep both events.
   */
  public function testConcurrentSuppressionUpdates(): void {
    $this->runConcurrentReceivers([123, 124]);
  }

  /**
   * Runs two receiver processes against the test's committed schema.
   */
  private function runConcurrentReceivers(array $ids): void {
    $workers = [];
    $input = [
      'root' => DRUPAL_ROOT,
      'database' => $this->container->get('database')->getConnectionOptions(),
    ];
    $script = dirname(__DIR__, 2) . '/fixtures/concurrent-receiver.php';
    try {
      for ($i = 0; $i < 2; $i++) {
        $process = proc_open([PHP_BINARY, $script], [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        $this->assertIsResource($process);
        $workers[] = [$process, $pipes];
        fwrite($pipes[0], json_encode($input + ['event_id' => $ids[$i]]) . "\n");
      }
      foreach ($workers as [, $pipes]) {
        stream_set_timeout($pipes[1], 20);
        $this->assertSame("ready\n", fgets($pipes[1]));
      }
      foreach ($workers as [, $pipes]) {
        fwrite($pipes[0], "go\n");
        fclose($pipes[0]);
      }
      foreach ($workers as [$process, $pipes]) {
        $this->assertSame('200', stream_get_contents($pipes[1]), stream_get_contents($pipes[2]));
        fclose($pipes[1]);
        fclose($pipes[2]);
        $this->assertSame(0, proc_close($process));
      }
      $this->assertSame(count(array_unique($ids)), $this->eventCount());
      $this->assertSame(1, (int) $this->container->get('database')->select('postmark_suppression')->countQuery()->execute()->fetchField());
    }
    finally {
      foreach ($workers as [$process, $pipes]) {
        foreach ($pipes as $pipe) {
          if (is_resource($pipe)) {
            fclose($pipe);
          }
        }
        if (is_resource($process)) {
          proc_terminate($process);
          proc_close($process);
        }
      }
    }
  }

  /**
   * Invalid payloads never leave partial or blank event rows.
   */
  public function testPayloadValidation(): void {
    $this->setSecret(self::SECRET);
    $base = ['RecordType' => 'Bounce', 'Type' => 'HardBounce', 'Email' => 'x@example.com'];
    $invalid = ['[]', '{}', 'null', '42', '"text"', '[{}]'];
    foreach ([
      ['RecordType' => NULL], ['RecordType' => []], ['RecordType' => ''],
      ['Email' => NULL], ['Email' => []], ['Email' => 'invalid'],
      ['Description' => []], ['Description' => str_repeat('x', 513)],
      ['MessageID' => 1], ['MessageID' => str_repeat('x', 256)],
      ['ID' => -1], ['ID' => 1.5], ['ID' => []], ['ServerID' => NULL],
      ['MessageStream' => new \stdClass()], ['BouncedAt' => []],
      ['Recipient' => 'different@example.com'], ['Type' => "bad\0value"],
    ] as $changes) {
      $invalid[] = json_encode($changes + $base);
    }
    foreach ($invalid as $body) {
      $this->assertSame(400, $this->receive($this->request(self::SECRET, $body))->getStatusCode());
    }
    $oversized = json_encode($base + ['Content' => str_repeat('x', 1048576)]);
    $this->assertSame(413, $this->receive($this->request(self::SECRET, $oversized))->getStatusCode());
    $this->assertSame(0, $this->eventCount());
    $valid = $base + [
      'Description' => str_repeat('é', 512),
      'ID' => '18446744073709551615',
      'Metadata' => ['nested' => ['accepted' => TRUE]],
    ];
    $this->assertSame(200, $this->receive($this->request(self::SECRET, json_encode($valid)))->getStatusCode());
    $metadata = 'leaf';
    for ($depth = 0; $depth < 64; $depth++) {
      $metadata = ['nested' => $metadata];
    }
    $valid['Metadata'] = $metadata;
    $this->assertSame(200, $this->receive($this->request(self::SECRET, json_encode($valid)))->getStatusCode());
    $this->assertSame(1, $this->eventCount());
  }

  /**
   * An incomplete bounce must not consume the identity of a corrected retry.
   */
  public function testIncompleteBounceDoesNotClaimIdentity(): void {
    $this->setSecret(self::SECRET);
    $base = ['RecordType' => 'Bounce', 'Email' => 'retry@example.com', 'ID' => 987];
    $policy = $this->container->get('postmark_webhooks.suppression_policy');
    foreach ([[], ['Type' => ''], ['Type' => ' '], ['Type' => ' HardBounce'], ['Type' => "HardBounce\t"]] as $type) {
      $body = json_encode($type + $base);
      $this->assertSame(400, $this->receive($this->request(self::SECRET, $body))->getStatusCode());
      $this->assertSame(0, $this->eventCount());
      $this->assertFalse($policy->decide('retry@example.com')->suppressed);
    }
    $body = json_encode(['Type' => 'HardBounce'] + $base);
    for ($retry = 0; $retry < 2; $retry++) {
      $this->assertSame(200, $this->receive($this->request(self::SECRET, $body))->getStatusCode());
    }
    $this->assertSame(1, $this->eventCount());
    $this->assertSame('hard:HardBounce', $policy->decide('retry@example.com')->reason);
    $this->assertTrue($policy->decide('retry@example.com')->suppressed);
  }

  /**
   * Future types remain log-only; other events need no bounce type.
   */
  public function testBounceTypeForwardCompatibility(): void {
    $this->setSecret(self::SECRET);
    foreach ([
      ['RecordType' => 'Bounce', 'Type' => 'FutureBounce'],
      ['RecordType' => 'Delivery'],
      ['RecordType' => 'FutureEvent'],
    ] as $event) {
      $body = json_encode($event + ['Recipient' => 'future@example.com', 'ID' => 321]);
      $this->assertSame(200, $this->receive($this->request(self::SECRET, $body))->getStatusCode());
    }
    $this->assertSame(3, $this->eventCount());
    $this->assertFalse($this->container->get('postmark_webhooks.suppression_policy')->decide('future@example.com')->suppressed);
  }

  /**
   * Configuration validation matches the admin form's numeric bounds.
   */
  public function testConfigWindowConstraints(): void {
    $manager = $this->container->get('config.typed');
    $data = $this->config('postmark_webhooks.settings')->getRawData();
    foreach (['bounce_suppression_days' => 365, 'complaint_suppression_days' => 3650, 'event_retention_days' => 3650] as $key => $max) {
      foreach ([-1, $max + 1] as $invalid) {
        $violations = $manager->createFromNameAndData('postmark_webhooks.settings', [$key => $invalid] + $data)->validate();
        $this->assertGreaterThan(0, count($violations));
      }
      $this->assertCount(0, $manager->createFromNameAndData('postmark_webhooks.settings', [$key => $max] + $data)->validate());
    }
  }

  /**
   * Import validation dispatch rejects invalid settings through the subscriber.
   */
  public function testConfigImportValidationSubscriber(): void {
    $source = new MemoryStorage();
    $target = new MemoryStorage();
    $data = $this->config('postmark_webhooks.settings')->getRawData();
    $source->write('postmark_webhooks.settings', ['bounce_suppression_days' => -1] + $data);
    $target->write('postmark_webhooks.settings', $data);
    $comparer = new StorageComparer($source, $target);
    $comparer->createChangelist();
    $importer = $this->getMockBuilder(ConfigImporter::class)
      ->disableOriginalConstructor()
      ->onlyMethods(['getStorageComparer', 'logError'])
      ->getMock();
    $importer->method('getStorageComparer')->willReturn($comparer);
    $importer->expects($this->once())->method('logError');
    $dispatcher = new EventDispatcher();
    $dispatcher->addSubscriber($this->container->get('postmark_webhooks.config_import_validator'));
    $dispatcher->dispatch(new ConfigImporterEvent($importer), ConfigEvents::IMPORT_VALIDATE);
  }

  /**
   * Delayed events use occurrence time without resetting windows.
   */
  public function testProviderOccurrencePolicy(): void {
    $this->setSecret(self::SECRET);
    $now = \Drupal::time()->getCurrentTime();
    $time = $this->createMock(TimeInterface::class);
    $time->method('getRequestTime')->willReturn($now);
    $time->method('getCurrentTime')->willReturn($now);
    $this->container->set('datetime.time', $time);
    $base = ['RecordType' => 'Bounce', 'Type' => 'SoftBounce', 'Email' => 'timed@example.com'];
    $send = function (int $id, string $date) use ($base): int {
      return $this->receive($this->request(self::SECRET, json_encode($base + ['ID' => $id, 'BouncedAt' => $date])))->getStatusCode();
    };
    $policy = $this->container->get('postmark_webhooks.suppression_policy');
    $this->assertSame(200, $send(1, gmdate('c', $now - 60 * 86400)));
    $this->assertFalse($policy->decide('timed@example.com')->suppressed);
    $this->assertSame(200, $send(2, gmdate('c', $now - 86400)));
    $new = $policy->decide('timed@example.com');
    $this->assertTrue($new->suppressed);
    $this->assertSame('provider', $new->timeBasis);
    $this->assertSame($now + 29 * 86400, $new->expires);
    $this->assertSame(200, $send(3, gmdate('c', $now - 20 * 86400)));
    $this->assertSame($new->expires, $policy->decide('timed@example.com')->expires);
    $this->assertSame(200, $send(4, gmdate('c', $now - 86400)));
    $this->assertSame($new->expires, $policy->decide('timed@example.com')->expires);
    $this->assertSame(400, $send(5, 'yesterday'));
    $this->assertSame(400, $send(6, '2026-02-30T00:00:00Z'));
    $this->assertSame(400, $send(7, gmdate('c', $now + 600)));
    $this->assertSame(200, $send(8, gmdate('c', $now + 120)));
    $this->assertSame($now, $policy->decide('timed@example.com')->occurred);
    $this->assertSame('clamped', $policy->decide('timed@example.com')->timeBasis);
  }

  /**
   * Failed state persistence rolls the event insert back for a safe retry.
   */
  public function testStateFailureRollsBackEvent(): void {
    $this->setSecret(self::SECRET);
    $database = $this->container->get('database');
    $database->schema()->dropTable('postmark_suppression');
    $this->expectException(DatabaseExceptionWrapper::class);
    try {
      $this->receive($this->request(self::SECRET));
    }
    finally {
      $this->assertSame(0, $this->eventCount());
    }
  }

  /**
   * Long-running requests retain the same receipt and clamping reference.
   */
  public function testReceiptTimeIsConsistent(): void {
    $this->setSecret(self::SECRET);
    $time = $this->createMock(TimeInterface::class);
    $time->method('getRequestTime')->willReturn(100);
    $time->method('getCurrentTime')->willReturn(160);
    $this->container->set('datetime.time', $time);
    $body = json_encode([
      'RecordType' => 'Bounce',
      'Type' => 'SoftBounce',
      'Email' => 'clock@example.com',
      'BouncedAt' => gmdate('c', 150),
    ]);
    $this->assertSame(200, $this->receive($this->request(self::SECRET, $body))->getStatusCode());
    $row = $this->container->get('database')->select('postmark_events')->fields('postmark_events')->execute()->fetchAssoc();
    $this->assertSame(100, (int) $row['created']);
    $this->assertSame(100, (int) $row['occurred']);
    $this->assertSame('clamped', $row['time_basis']);
  }

  /**
   * Previous credentials work only before a fixed expiry and can be removed.
   */
  public function testSecretRotationBoundaries(): void {
    $settings = Settings::getAll();
    $time = $this->createMock(TimeInterface::class);
    $time->method('getRequestTime')->willReturn(100);
    $time->method('getCurrentTime')->willReturnOnConsecutiveCalls(199, 199, 200, 201, 201);
    $this->container->set('datetime.time', $time);
    new Settings([
      'postmark_webhooks.webhook_secret' => self::SECRET,
      'postmark_webhooks.previous_webhook_secret' => ['secret' => 'previous-test-only', 'expires' => 200],
    ] + $settings);
    $this->assertSame(200, $this->receive($this->request(self::SECRET))->getStatusCode());
    $this->assertSame(200, $this->receive($this->request('previous-test-only'))->getStatusCode());
    $this->assertSame(401, $this->receive($this->request('previous-test-only'))->getStatusCode());
    $this->assertSame(200, $this->receive($this->request(self::SECRET))->getStatusCode());
    new Settings(['postmark_webhooks.webhook_secret' => self::SECRET] + $settings);
    $this->assertSame(401, $this->receive($this->request('previous-test-only'))->getStatusCode());
    $this->assertSame(1, $this->eventCount());
  }

  /**
   * Malformed settings cannot authorize a request or expose their values.
   */
  public function testMalformedRotationSettings(): void {
    $settings = Settings::getAll();
    foreach ([NULL, '', [], 123, TRUE, new \stdClass()] as $active) {
      new Settings([
        'postmark_webhooks.webhook_secret' => $active,
        'postmark_webhooks.previous_webhook_secret' => ['secret' => 'old-test-only', 'expires' => PHP_INT_MAX],
      ] + $settings);
      $response = $this->receive($this->request('old-test-only'));
      $this->assertSame(503, $response->getStatusCode());
      $this->assertSame('Service Unavailable', $response->getContent());
    }
    foreach ([
      '', 42, [], ['secret' => []],
      ['secret' => '', 'expires' => PHP_INT_MAX],
      ['secret' => 'old-test-only', 'expires' => '9999999999'],
      ['secret' => 'old-test-only', 'expires' => 0],
      ['secret' => 'old-test-only', 'expires' => 1.5],
    ] as $previous) {
      new Settings([
        'postmark_webhooks.webhook_secret' => self::SECRET,
        'postmark_webhooks.previous_webhook_secret' => $previous,
      ] + $settings);
      $this->assertSame(401, $this->receive($this->request('old-test-only'))->getStatusCode());
      $this->assertSame(200, $this->receive($this->request(self::SECRET))->getStatusCode());
    }
    $this->assertSame(1, $this->eventCount());
    $config = $this->config('postmark_webhooks.settings')->getRawData();
    $this->assertArrayNotHasKey('previous_webhook_secret', $config);
    $this->assertStringNotContainsString(self::SECRET, json_encode($config));
    $this->assertStringNotContainsString('old-test-only', json_encode($config));
  }

}
