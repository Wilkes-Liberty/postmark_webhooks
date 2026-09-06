<?php

declare(strict_types=1);

namespace Drupal\Tests\postmark_webhooks\Kernel;

use Drupal\KernelTests\KernelTestBase;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Proves cleanup batch boundaries preserve current events and durable policy.
 *
 * @group postmark_webhooks
 */
#[RunTestsInSeparateProcesses]
class PostmarkRetentionTest extends KernelTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = ['postmark_webhooks'];

  /**
   * Repeated cleanup drains only expired history, including late arrivals.
   */
  public function testBoundedCleanup(): void {
    $this->installSchema('postmark_webhooks', ['postmark_events', 'postmark_suppression']);
    $this->installConfig(['postmark_webhooks']);
    $database = $this->container->get('database');
    $insert = $database->insert('postmark_events')->fields(['created', 'recipient']);
    for ($i = 0; $i < 501; $i++) {
      $insert->values([1, 'bounded@example.com']);
    }
    $insert->values([100, 'boundary@example.com'])->values([200, 'fresh@example.com'])->execute();
    $this->container->get('postmark_webhooks.suppression_store')->record([
      'event_type' => 'Bounce',
      'bounce_type' => 'HardBounce',
      'recipient' => 'BOUNDED@example.com',
      'created' => 1,
      'event_key' => str_repeat('b', 64),
    ]);
    $retention = $this->container->get('postmark_webhooks.event_retention');
    $this->assertSame(250, $retention->purgeBefore(100));
    // An older event arriving between runs must not be skipped by a cursor.
    $database->insert('postmark_events')->fields(['created' => 2])->execute();
    $this->assertSame(250, $retention->purgeBefore(100));
    $this->assertSame(2, $retention->purgeBefore(100));
    $this->assertSame(0, $retention->purgeBefore(100));
    $remaining = $database->select('postmark_events', 'pe')->fields('pe', ['recipient'])->orderBy('created')->execute()->fetchCol();
    $this->assertSame(['boundary@example.com', 'fresh@example.com'], $remaining);
    $this->assertTrue($this->container->get('postmark_webhooks.suppression_policy')->decide('bounded@example.com')->suppressed);
  }

  /**
   * Concurrent cleanup and intake preserve new events and suppression state.
   */
  public function testConcurrentCleanupAndIntake(): void {
    $this->installSchema('postmark_webhooks', ['postmark_events', 'postmark_suppression']);
    $database = $this->container->get('database');
    $insert = $database->insert('postmark_events')->fields(['created']);
    for ($i = 0; $i < 501; $i++) {
      $insert->values([1]);
    }
    $insert->execute();
    $input = ['root' => DRUPAL_ROOT, 'database' => $database->getConnectionOptions()];
    $workers = [];
    $results = [];
    try {
      foreach ([['purge_before' => 100], ['purge_before' => 100], ['event_id' => 987]] as $operation) {
        $process = proc_open([PHP_BINARY, dirname(__DIR__, 2) . '/fixtures/concurrent-receiver.php'], [
          0 => ['pipe', 'r'],
          1 => ['pipe', 'w'],
          2 => ['pipe', 'w'],
        ], $pipes);
        $this->assertIsResource($process);
        $workers[] = [$process, $pipes];
        fwrite($pipes[0], json_encode($input + $operation) . "\n");
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
        $results[] = (int) stream_get_contents($pipes[1]);
        $this->assertSame('', stream_get_contents($pipes[2]));
        fclose($pipes[1]);
        fclose($pipes[2]);
        $this->assertSame(0, proc_close($process));
      }
      $this->assertSame(200, $results[2]);
      $this->assertLessThanOrEqual(250, $results[0]);
      $this->assertLessThanOrEqual(250, $results[1]);
      $this->assertSame(502 - $results[0] - $results[1], (int) $database->select('postmark_events')->countQuery()->execute()->fetchField());
      $this->assertSame(1, (int) $database->select('postmark_suppression')->countQuery()->execute()->fetchField());
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

}
