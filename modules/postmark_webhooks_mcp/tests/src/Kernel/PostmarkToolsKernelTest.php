<?php

declare(strict_types=1);

namespace Drupal\Tests\postmark_webhooks_mcp\Kernel;

use Drupal\Core\Session\AnonymousUserSession;
use Drupal\KernelTests\KernelTestBase;
use Drupal\Tests\user\Traits\UserCreationTrait;
use Drupal\user\Entity\Role;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Exercises discovery and direct execution against source governance.
 *
 * @group postmark_webhooks
 *
 * @runTestsInSeparateProcesses
 */
#[Group('postmark_webhooks')]
#[RunTestsInSeparateProcesses]
final class PostmarkToolsKernelTest extends KernelTestBase {

  use UserCreationTrait;

  /**
   * Mailbox seeded into storage. It must never reach a tool result.
   */
  private const MAILBOX = 'private.person@example.com';

  /**
   * Tools that need no input and only the shared permission.
   */
  private const PLAIN_TOOLS = [
    'postmark_webhooks_health',
    'postmark_webhooks_diagnostics',
    'postmark_webhooks_outbox_status',
  ];

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'system', 'user', 'field', 'filter', 'text', 'file', 'node',
    'serialization', 'jsonapi', 'tool', 'key', 'image', 'options',
    'path_alias', 'consumers', 'simple_oauth', 'encrypt', 'audit_chain',
    'mcp_sentinel', 'postmark_webhooks', 'postmark_webhooks_mcp',
  ];

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->installSchema('audit_chain', ['audit_chain_log', 'audit_chain_mutex']);
    $this->container->get('database')->insert('audit_chain_mutex')
      ->fields(['id' => 1, 'locked' => 1])->execute();
    $this->installSchema('postmark_webhooks', [
      'postmark_events',
      'postmark_suppression',
      'postmark_intake_metrics',
      'postmark_operator_audit',
      'postmark_integration_outbox',
    ]);
    $this->installEntitySchema('user');
    $this->installConfig(['system', 'user', 'mcp_sentinel', 'postmark_webhooks']);
    $role = Role::load('mcp_api') ?? Role::create(['id' => 'mcp_api', 'label' => 'MCP API']);
    $role->grantPermission('access mcp sentinel context')
      ->grantPermission('use postmark webhooks mcp tools')
      ->save();
    $this->config('mcp_sentinel.settings')->set('governed_role_fallback', TRUE)->save();
    $this->setUpCurrentUser(['roles' => ['mcp_api']]);
  }

  /**
   * Plain tools run for a governed account and refuse an anonymous one.
   */
  public function testGovernedToolsAndAnonymousDenial(): void {
    $account = $this->container->get('current_user')->getAccount();
    foreach (self::PLAIN_TOOLS as $id) {
      $this->container->get('current_user')->setAccount($account);
      $tool = $this->tool($id);
      self::assertTrue($tool->discoveryAccess($account)->isAllowed(), $id);
      self::assertTrue($tool->access(), $id);
      $tool->execute();
      self::assertTrue($tool->getResultStatus(), $id . ': ' . $tool->getResultMessage());
      self::assertNotEmpty($tool->getResult()->getContextValues(), $id);

      $this->container->get('current_user')->setAccount(new AnonymousUserSession());
      $denied = $this->tool($id);
      self::assertFalse($denied->discoveryAccess(new AnonymousUserSession())->isAllowed(), $id);
      self::assertFalse($denied->access(), $id);
      $denied->execute();
      self::assertFalse($denied->getResultStatus(), $id);
      self::assertEmpty($denied->getResult()->getContextValues(), $id);
    }
  }

  /**
   * Sentinel access alone does not grant the module's tools.
   */
  public function testModulePermissionIsRequired(): void {
    Role::load('mcp_api')->revokePermission('use postmark webhooks mcp tools')->save();
    $account = $this->container->get('current_user');
    foreach (self::PLAIN_TOOLS as $id) {
      $tool = $this->tool($id);
      self::assertFalse($tool->discoveryAccess($account)->isAllowed(), $id);
      self::assertFalse($tool->access(), $id);
      $tool->execute();
      self::assertFalse($tool->getResultStatus(), $id);
      self::assertEmpty($tool->getResult()->getContextValues(), $id);
    }
  }

  /**
   * Disabled auditing makes governance not ready, so every tool refuses.
   */
  public function testGovernanceNotReadyRefusesDirectExecution(): void {
    $this->config('mcp_sentinel.settings')->set('audit_enabled', FALSE)->save();
    $tool = $this->tool('postmark_webhooks_health');
    self::assertFalse($tool->discoveryAccess($this->container->get('current_user'))->isAllowed());
    self::assertFalse($tool->access());
    $tool->execute();
    self::assertFalse($tool->getResultStatus());
    self::assertEmpty($tool->getResult()->getContextValues());
  }

  /**
   * The preview reports the block, never the address, and audits a hash.
   */
  public function testDeliveryPreviewHidesAndAuditsTheAddress(): void {
    $this->seedEvent('msg-1', 'Bounce', 'HardBounce');
    $tool = $this->tool('postmark_webhooks_delivery_preview');
    $tool->setInputValue('email', self::MAILBOX);
    self::assertTrue($tool->access());
    $tool->execute();
    self::assertTrue($tool->getResultStatus(), (string) $tool->getResultMessage());
    $values = $tool->getResult()->getContextValues();
    self::assertSame('core', $values['preview']['mail_path']);
    self::assertArrayHasKey('suppressed', $values['preview']['policy']);
    $this->assertNoMailbox($values, (string) $tool->getResultMessage());

    $rows = $this->container->get('database')->select('postmark_operator_audit', 'a')
      ->fields('a', ['action', 'subject', 'uid'])->execute()->fetchAll();
    self::assertCount(1, $rows);
    self::assertSame('mcp_delivery_preview', $rows[0]->action);
    self::assertMatchesRegularExpression('/^[0-9a-f]{64}$/', $rows[0]->subject);
    self::assertStringNotContainsString('example.com', $rows[0]->subject);
  }

  /**
   * Bad input refuses with the fixed message and writes no audit row.
   */
  public function testInvalidInputDoesNotLeakOrAudit(): void {
    $cases = [
      ['email' => 'not-an-address-7Q'],
      ['email' => self::MAILBOX, 'mail_path' => 'carrier-pigeon-7Q'],
      ['email' => self::MAILBOX, 'server_id' => '123'],
      ['email' => self::MAILBOX, 'message_stream' => 'outbound-7Q'],
    ];
    foreach ($cases as $inputs) {
      $tool = $this->tool('postmark_webhooks_delivery_preview');
      foreach ($inputs as $name => $value) {
        try {
          $tool->setInputValue($name, $value);
        }
        catch (\Throwable) {
          // A typed-data refusal at input time is as good as one at execute.
          continue 2;
        }
      }
      try {
        $tool->execute();
      }
      catch (\Throwable) {
        continue;
      }
      self::assertFalse($tool->getResultStatus(), json_encode($inputs));
      self::assertStringNotContainsString('7Q', (string) $tool->getResultMessage());
      $this->assertNoMailbox($tool->getResult()->getContextValues(), (string) $tool->getResultMessage());
    }
    $count = (int) $this->container->get('database')->select('postmark_operator_audit', 'a')
      ->countQuery()->execute()->fetchField();
    self::assertSame(0, $count);
  }

  /**
   * The timeline needs the view permission and returns labels, not mailboxes.
   */
  public function testTimelineRequiresViewPermissionAndMasksRecipients(): void {
    $this->seedEvent('msg-1', 'Delivery');
    $this->seedEvent('msg-1', 'Bounce', 'HardBounce');
    $account = $this->container->get('current_user');

    $tool = $this->tool('postmark_webhooks_message_timeline');
    $tool->setInputValue('message_id', 'msg-1');
    self::assertFalse($tool->discoveryAccess($account)->isAllowed());
    self::assertFalse($tool->access());
    $tool->execute();
    self::assertFalse($tool->getResultStatus());

    Role::load('mcp_api')->grantPermission('view postmark suppression')->save();
    $tool = $this->tool('postmark_webhooks_message_timeline');
    $tool->setInputValue('message_id', 'msg-1');
    self::assertTrue($tool->discoveryAccess($account)->isAllowed());
    self::assertTrue($tool->access());
    $tool->execute();
    self::assertTrue($tool->getResultStatus(), (string) $tool->getResultMessage());
    $values = $tool->getResult()->getContextValues();
    self::assertSame(2, $values['total']);
    self::assertSame('recipient_1', $values['events'][0]['recipient']);
    self::assertSame('recipient_1', $values['events'][1]['recipient']);
    self::assertArrayNotHasKey('suppressed', $values);
    self::assertArrayNotHasKey('message_id', $values);
    $this->assertNoMailbox($values, (string) $tool->getResultMessage());
    self::assertStringNotContainsString('should-not-surface', json_encode($values));
  }

  /**
   * Drift reports stay hidden while the reconciliation submodule is absent.
   */
  public function testDriftReportsHiddenWithoutReconcile(): void {
    $tool = $this->tool('postmark_webhooks_drift_reports');
    self::assertFalse($tool->discoveryAccess($this->container->get('current_user'))->isAllowed());
    self::assertFalse($tool->access());
    $tool->execute();
    self::assertFalse($tool->getResultStatus());
    self::assertEmpty($tool->getResult()->getContextValues());
  }

  /**
   * No plain tool output carries a stored mailbox.
   */
  public function testPlainToolsCarryNoMailbox(): void {
    $this->seedEvent('msg-9', 'Bounce', 'HardBounce');
    foreach (self::PLAIN_TOOLS as $id) {
      $tool = $this->tool($id);
      $tool->execute();
      self::assertTrue($tool->getResultStatus(), $id);
      $this->assertNoMailbox($tool->getResult()->getContextValues(), (string) $tool->getResultMessage());
    }
  }

  /**
   * Creates a fresh tool instance.
   */
  private function tool(string $id): object {
    return $this->container->get('plugin.manager.tool')->createInstance($id);
  }

  /**
   * Fails when the seeded mailbox, or any part of it, appears.
   */
  private function assertNoMailbox(array $values, string $message): void {
    $haystack = json_encode($values, JSON_THROW_ON_ERROR) . $message;
    self::assertStringNotContainsString(self::MAILBOX, $haystack);
    self::assertStringNotContainsString('private.person', $haystack);
    self::assertStringNotContainsString('example.com', $haystack);
  }

  /**
   * Inserts one retained event and, for a hard bounce, its suppression.
   */
  private function seedEvent(string $message_id, string $type, string $bounce = ''): void {
    static $sequence = 0;
    $sequence++;
    $this->container->get('database')->insert('postmark_events')->fields([
      'message_id' => $message_id,
      'event_type' => $type,
      'bounce_type' => $bounce,
      'recipient' => self::MAILBOX,
      'server_id' => '1',
      'message_stream' => 'outbound',
      'occurred' => 100 + $sequence,
      'created' => 100 + $sequence,
      'time_basis' => 'receipt',
      'event_key' => hash('sha256', $message_id . $sequence),
      'description' => 'should-not-surface',
    ])->execute();
  }

}
