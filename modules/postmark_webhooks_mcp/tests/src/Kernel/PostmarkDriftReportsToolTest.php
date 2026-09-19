<?php

declare(strict_types=1);

namespace Drupal\Tests\postmark_webhooks_mcp\Kernel;

use Drupal\KernelTests\KernelTestBase;
use Drupal\Tests\user\Traits\UserCreationTrait;
use Drupal\user\Entity\Role;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Exercises the drift report tool with the reconciliation submodule installed.
 *
 * @group postmark_webhooks
 *
 * @runTestsInSeparateProcesses
 */
#[Group('postmark_webhooks')]
#[RunTestsInSeparateProcesses]
final class PostmarkDriftReportsToolTest extends KernelTestBase {

  use UserCreationTrait;

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'system', 'user', 'field', 'filter', 'text', 'file', 'node',
    'serialization', 'jsonapi', 'tool', 'key', 'image', 'options',
    'path_alias', 'consumers', 'simple_oauth', 'encrypt', 'audit_chain',
    'mcp_sentinel', 'postmark_webhooks', 'postmark_webhooks_reconcile',
    'postmark_webhooks_mcp',
  ];

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->installSchema('audit_chain', ['audit_chain_log', 'audit_chain_mutex']);
    $this->container->get('database')->insert('audit_chain_mutex')
      ->fields(['id' => 1, 'locked' => 1])->execute();
    $this->installSchema('postmark_webhooks', ['postmark_intake_metrics']);
    $this->installSchema('postmark_webhooks_reconcile', ['postmark_drift_report']);
    $this->installEntitySchema('user');
    $this->installConfig(['system', 'user', 'mcp_sentinel', 'postmark_webhooks', 'postmark_webhooks_reconcile']);
    $role = Role::load('mcp_api') ?? Role::create(['id' => 'mcp_api', 'label' => 'MCP API']);
    $role->grantPermission('access mcp sentinel context')
      ->grantPermission('use postmark webhooks mcp tools')
      ->save();
    $this->config('mcp_sentinel.settings')->set('governed_role_fallback', TRUE)->save();
    $this->setUpCurrentUser(['roles' => ['mcp_api']]);
  }

  /**
   * Reports come back newest first and honour the limit.
   */
  public function testReportsAreListedAndBounded(): void {
    foreach ([100, 200, 300] as $created) {
      $this->container->get('database')->insert('postmark_drift_report')->fields([
        'report_id' => 'r' . $created,
        'server_id' => '1',
        'message_stream' => 'outbound',
        'source_date' => '2026-01-01',
        'created' => $created,
        'status' => 'complete',
        'incomplete_reason' => '',
        'new_evidence' => 2,
        'newer_evidence' => 0,
        'unchanged_or_older' => 5,
        'reasons' => '{"HardBounce":2}',
        'provider_total' => 7,
        'digest' => str_repeat('a', 64),
      ])->execute();
    }
    $tool = $this->container->get('plugin.manager.tool')->createInstance('postmark_webhooks_drift_reports');
    $tool->setInputValue('limit', 2);
    self::assertTrue($tool->discoveryAccess($this->container->get('current_user'))->isAllowed());
    self::assertTrue($tool->access());
    $tool->execute();
    self::assertTrue($tool->getResultStatus(), (string) $tool->getResultMessage());
    $reports = $tool->getResult()->getContextValues()['reports'];
    self::assertCount(2, $reports);
    self::assertSame('r300', $reports[0]['report_id']);
    self::assertSame(2, $reports[0]['differences']['new_evidence']);
    self::assertFalse($reports[0]['provider_writes']);
  }

}
