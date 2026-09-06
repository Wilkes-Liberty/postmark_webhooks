<?php

declare(strict_types=1);

namespace Drupal\Tests\postmark_webhooks\Kernel;

use Drupal\Core\Database\DatabaseExceptionWrapper;
use Drupal\Core\Session\AccountInterface;
use Drupal\KernelTests\KernelTestBase;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;

/**
 * Verifies narrowly scoped recovery, authorization and atomic audit records.
 *
 * @group postmark_webhooks
 */
#[RunTestsInSeparateProcesses]
class PostmarkInspectorTest extends KernelTestBase {

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
   * Builds an actor with exactly the requested permissions.
   */
  private function actor(array $permissions): AccountInterface {
    $actor = $this->createMock(AccountInterface::class);
    $actor->method('id')->willReturn(42);
    $actor->method('hasPermission')->willReturnCallback(static fn(string $permission): bool => in_array($permission, $permissions, TRUE));
    return $actor;
  }

  /**
   * Stores source-specific synthetic evidence and returns its current row.
   */
  private function seed(string $type = 'HardBounce', string $server = '1'): object {
    $this->container->get('postmark_webhooks.suppression_store')->record([
      'event_type' => 'Bounce',
      'bounce_type' => $type,
      'recipient' => 'private@example.com',
      'server_id' => $server,
      'message_stream' => 'outbound',
      'created' => 10,
      'event_key' => str_repeat('a', 64),
    ]);
    return $this->container->get('database')->select('postmark_suppression', 's')->fields('s')
      ->condition('reason', 'hard:' . $type)->condition('server_id', $server)->execute()->fetchObject();
  }

  /**
   * View permission never grants recovery and anonymous users cannot inspect.
   */
  public function testPermissions(): void {
    $inspector = $this->container->get('postmark_webhooks.inspector');
    try {
      $inspector->inspect('private@example.com', $this->actor([]));
      $this->fail('Inspection must be denied.');
    }
    catch (AccessDeniedHttpException) {
      $this->assertTrue(TRUE);
    }
    $row = $this->seed();
    $viewer = $this->actor(['view postmark suppression']);
    $this->assertTrue($inspector->inspect('private@example.com', $viewer)['decision']['suppressed']);
    $this->expectException(AccessDeniedHttpException::class);
    $inspector->recover($row->state_key, $row->evidence, $viewer);
  }

  /**
   * Release does not erase other sources, complaints or opt-outs.
   */
  public function testRecoveryPreservesOtherEvidence(): void {
    $row = $this->seed();
    $actor = $this->actor(['view postmark suppression', 'recover postmark hard bounces']);
    $inspector = $this->container->get('postmark_webhooks.inspector');
    $inspector->recover($row->state_key, $row->evidence, $actor);
    $this->assertFalse($inspector->inspect('private@example.com', $actor)['decision']['suppressed']);
    $audit = $this->container->get('database')->select('postmark_operator_audit', 'a')->fields('a')->execute()->fetchAssoc();
    $this->assertSame('recover_hard', $audit['action']);
    $this->assertSame($row->state_key, $audit['target']);
    $this->assertSame(42, (int) $audit['uid']);
    $this->assertSame(64, strlen($audit['subject']));
    $this->assertStringNotContainsString('private@example.com', json_encode($audit));
    $this->seed('HardBounce', '2');
    $this->assertTrue($inspector->inspect('private@example.com', $actor)['decision']['suppressed']);
    foreach (['Unsubscribe', 'ManuallyDeactivated'] as $type) {
      $protected = $this->seed($type);
      try {
        $inspector->recover($protected->state_key, $protected->evidence, $actor);
        $this->fail('Opt-outs must not be released.');
      }
      catch (\InvalidArgumentException) {
        $this->assertTrue(TRUE);
      }
    }
    $this->container->get('postmark_webhooks.suppression_store')->record([
      'event_type' => 'SpamComplaint',
      'recipient' => 'private@example.com',
      'server_id' => '1',
      'message_stream' => 'outbound',
      'created' => 10,
      'event_key' => str_repeat('b', 64),
    ]);
    $this->assertSame('spam:SpamComplaint', $inspector->inspect('private@example.com', $actor)['decision']['reason']);
  }

  /**
   * A pending confirmation cannot substitute new evidence silently.
   */
  public function testStaleConfirmation(): void {
    $row = $this->seed();
    $this->expectException(\InvalidArgumentException::class);
    $actor = $this->actor(['view postmark suppression', 'recover postmark hard bounces']);
    $this->container->get('postmark_webhooks.inspector')->recover($row->state_key, 'stale', $actor);
  }

  /**
   * Audit failures roll back the release instead of silently unblocking mail.
   */
  public function testAuditFailureRollsBack(): void {
    $row = $this->seed();
    $actor = $this->actor(['view postmark suppression', 'recover postmark hard bounces']);
    $database = $this->container->get('database');
    $database->schema()->dropTable('postmark_operator_audit');
    $this->expectException(DatabaseExceptionWrapper::class);
    try {
      $this->container->get('postmark_webhooks.inspector')->recover($row->state_key, $row->evidence, $actor);
    }
    finally {
      $this->assertSame(0, (int) $database->select('postmark_suppression')->condition('reason', 'release:hard')->countQuery()->execute()->fetchField());
    }
  }

  /**
   * The audit upgrade is repeatable and preserves existing action records.
   */
  public function testAuditUpdate(): void {
    $database = $this->container->get('database');
    $database->schema()->dropTable('postmark_operator_audit');
    $this->container->get('module_handler')->loadInclude('postmark_webhooks', 'install');
    postmark_webhooks_update_10005();
    $this->container->get('postmark_webhooks.operator_audit')->record('test_action', 'private@example.com', $this->actor([]), 10);
    postmark_webhooks_update_10005();
    $this->assertSame(1, (int) $database->select('postmark_operator_audit')->countQuery()->execute()->fetchField());
  }

}
