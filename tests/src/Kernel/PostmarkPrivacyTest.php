<?php

declare(strict_types=1);

namespace Drupal\Tests\postmark_webhooks\Kernel;

use Drupal\Core\Session\AccountInterface;
use Drupal\Core\Database\DatabaseExceptionWrapper;
use Drupal\KernelTests\KernelTestBase;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use Symfony\Component\HttpFoundation\StreamedJsonResponse;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;

/**
 * Verifies bounded export/erasure, audit and retained suppression guarantees.
 *
 * @group postmark_webhooks
 */
#[RunTestsInSeparateProcesses]
class PostmarkPrivacyTest extends KernelTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = ['system', 'postmark_webhooks'];

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->installSchema('postmark_webhooks', ['postmark_events', 'postmark_suppression', 'postmark_operator_audit']);
    $this->installConfig(['postmark_webhooks']);
  }

  /**
   * Creates an actor with an explicit permission set.
   */
  private function actor(array $permissions): AccountInterface {
    $actor = $this->createMock(AccountInterface::class);
    $actor->method('id')->willReturn(42);
    $actor->method('hasPermission')->willReturnCallback(static fn(string $permission): bool => in_array($permission, $permissions, TRUE));
    return $actor;
  }

  /**
   * Seeds legacy history including free text that must never be exported.
   */
  private function seed(int $count, string $recipient = 'private@example.com'): void {
    $database = $this->container->get('database');
    for ($i = 0; $i < $count; $i++) {
      $database->insert('postmark_events')->fields([
        'recipient' => $recipient,
        'event_type' => 'Bounce',
        'bounce_type' => 'HardBounce',
        'created' => 10,
        'message_stream' => '<script>alert(1)</script>',
        'description' => 'private-provider-text',
        'payload' => 'secret-legacy-body',
      ])->execute();
    }
    $this->container->get('postmark_webhooks.suppression_store')->record([
      'recipient' => $recipient,
      'event_type' => 'Bounce',
      'bounce_type' => 'HardBounce',
      'created' => 10,
      'event_key' => str_repeat('a', 64),
    ]);
  }

  /**
   * Export spans database pages, escapes text, and omits raw provider content.
   */
  public function testStreamedExport(): void {
    $this->seed(251);
    $privacy = $this->container->get('postmark_webhooks.recipient_privacy');
    $data = $privacy->export('PRIVATE@example.com', $this->actor(['export postmark recipient data']));
    $response = new StreamedJsonResponse($data, 200, [], JSON_HEX_TAG);
    ob_start();
    $response->sendContent();
    $json = ob_get_clean();
    $export = json_decode($json, TRUE, 512, JSON_THROW_ON_ERROR);
    $this->assertCount(251, $export['events']);
    $this->assertCount(1, $export['suppression']);
    $this->assertStringNotContainsString('<script>', $json);
    $this->assertStringNotContainsString('private-provider-text', $json);
    $this->assertStringNotContainsString('secret-legacy-body', $json);
    $audit = $this->container->get('database')->select('postmark_operator_audit', 'a')->fields('a')->execute()->fetchAssoc();
    $this->assertSame('export_requested', $audit['action']);
    $this->assertStringNotContainsString('private@example.com', json_encode($audit));
  }

  /**
   * Erasure is bounded and excludes new intake and other recipients.
   */
  public function testErasureSnapshotAndSuppression(): void {
    $this->seed(251);
    $privacy = $this->container->get('postmark_webhooks.recipient_privacy');
    $actor = $this->actor(['erase postmark recipient history']);
    $maximum = $privacy->erasureBoundary('private@example.com', $actor);
    $this->seed(1);
    $this->seed(1, 'other@example.com');
    $this->assertSame(250, $privacy->eraseBatch('private@example.com', $maximum, $actor));
    $this->assertTrue($privacy->hasHistory('private@example.com', $maximum, $actor));
    $this->assertSame(1, $privacy->eraseBatch('private@example.com', $maximum, $actor));
    $this->assertFalse($privacy->hasHistory('private@example.com', $maximum, $actor));
    $this->assertSame(0, $privacy->eraseBatch('private@example.com', $maximum, $actor));
    $database = $this->container->get('database');
    $this->assertSame(2, (int) $database->select('postmark_events')->countQuery()->execute()->fetchField());
    $this->assertSame(2, (int) $database->select('postmark_operator_audit')->countQuery()->execute()->fetchField());
    $this->assertTrue($this->container->get('postmark_webhooks.suppression_policy')->decide('private@example.com')->suppressed);
  }

  /**
   * Export permission does not imply erasure permission.
   */
  public function testSeparatePermissions(): void {
    $this->expectException(AccessDeniedHttpException::class);
    $this->container->get('postmark_webhooks.recipient_privacy')->eraseBatch('private@example.com', 100, $this->actor(['export postmark recipient data']));
  }

  /**
   * History deletion rolls back if its audit record cannot be written.
   */
  public function testErasureAuditRollback(): void {
    $this->seed(1);
    $database = $this->container->get('database');
    $database->schema()->dropTable('postmark_operator_audit');
    $this->expectException(DatabaseExceptionWrapper::class);
    try {
      $this->container->get('postmark_webhooks.recipient_privacy')->eraseBatch('private@example.com', 100, $this->actor(['erase postmark recipient history']));
    }
    finally {
      $this->assertSame(1, (int) $database->select('postmark_events')->countQuery()->execute()->fetchField());
    }
  }

  /**
   * Legacy free-text scrubbing is bounded, restartable and policy preserving.
   */
  public function testLegacyTextScrub(): void {
    $this->seed(251);
    $this->container->get('module_handler')->loadInclude('postmark_webhooks', 'install');
    $sandbox = [];
    postmark_webhooks_update_10006($sandbox);
    $database = $this->container->get('database');
    $this->assertSame(1, (int) $database->select('postmark_events')->condition('description', 'private-provider-text')->countQuery()->execute()->fetchField());
    $this->assertLessThan(1, $sandbox['#finished']);
    postmark_webhooks_update_10006($sandbox);
    $this->assertSame(1, $sandbox['#finished']);
    $this->assertSame(0, (int) $database->select('postmark_events')->isNotNull('payload')->countQuery()->execute()->fetchField());
    $this->assertTrue($this->container->get('postmark_webhooks.suppression_policy')->decide('private@example.com')->suppressed);
  }

}
