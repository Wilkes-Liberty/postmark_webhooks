<?php

declare(strict_types=1);

namespace Drupal\Tests\postmark_webhooks\Kernel;

use Drupal\KernelTests\KernelTestBase;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Verifies update 10007 creates the integration outbox on Drupal 11.
 *
 * @group postmark_webhooks
 */
#[RunTestsInSeparateProcesses]
class PostmarkUpdate10007Test extends KernelTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = ['system', 'postmark_webhooks'];

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->installSchema('postmark_webhooks', [
      'postmark_events',
      'postmark_suppression',
      'postmark_intake_metrics',
    ]);
    $this->installConfig(['postmark_webhooks']);
  }

  /**
   * Existing sites get the outbox table without the removed schema helper.
   */
  public function testUpdateCreatesOutboxTable(): void {
    $schema = $this->container->get('database')->schema();
    $this->assertFalse($schema->tableExists('postmark_integration_outbox'));
    $this->container->get('module_handler')->loadInclude('postmark_webhooks', 'install');
    postmark_webhooks_update_10007();
    $this->assertTrue($schema->tableExists('postmark_integration_outbox'));
    postmark_webhooks_update_10007();
    $this->assertTrue($schema->tableExists('postmark_integration_outbox'));
  }

}
