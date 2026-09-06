<?php

declare(strict_types=1);

namespace Drupal\Tests\postmark_webhooks\Kernel;

use Drupal\KernelTests\KernelTestBase;
use Drupal\postmark_webhooks\Source\SourceContext;
use Drupal\postmark_webhooks\Suppression\SuppressionPolicyInterface;
use Drupal\postmark_webhooks_consumer_test\SendGate;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Verifies the documented 1.x public suppression contract.
 *
 * @group postmark_webhooks
 */
#[RunTestsInSeparateProcesses]
class PostmarkPublicApiTest extends KernelTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'system',
    'postmark_webhooks',
    'postmark_webhooks_consumer_test',
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
    ]);
    $this->installConfig(['postmark_webhooks']);
  }

  /**
   * The service ID and interface alias are the same object.
   */
  public function testServiceAlias(): void {
    $by_id = $this->container->get('postmark_webhooks.suppression_policy');
    $by_interface = $this->container->get(SuppressionPolicyInterface::class);
    $this->assertSame($by_id, $by_interface);
    $this->assertInstanceOf(SuppressionPolicyInterface::class, $by_interface);
  }

  /**
   * Decision JSON keys stay stable and never include a recipient.
   */
  public function testDecisionJsonContract(): void {
    $policy = $this->container->get(SuppressionPolicyInterface::class);
    $allowed = $policy->decide('Nobody@example.com');
    $this->assertSame(
      ['suppressed', 'reason', 'expires', 'evidence', 'occurred', 'timeBasis'],
      array_keys($allowed->jsonSerialize()),
    );
    $this->assertFalse($allowed->suppressed);
    $this->assertSame('no_active_suppression', $allowed->reason);
    $this->assertArrayNotHasKey('recipient', $allowed->jsonSerialize());

    $this->container->get('postmark_webhooks.suppression_store')->record([
      'event_type' => 'Bounce',
      'bounce_type' => 'HardBounce',
      'recipient' => 'blocked@example.com',
      'created' => 10,
      'event_key' => str_repeat('a', 64),
    ]);
    $blocked = $policy->decide('BLOCKED@example.com');
    $this->assertTrue($blocked->suppressed);
    $this->assertSame('hard:HardBounce', $blocked->reason);
    $this->assertNull($blocked->expires);
    $this->assertNotNull($blocked->evidence);
    $this->assertSame('legacy', $blocked->timeBasis);

    $this->config('postmark_webhooks.settings')->set('enabled', FALSE)->save();
    $disabled = $policy->decide('blocked@example.com');
    $this->assertFalse($disabled->suppressed);
    $this->assertSame('disabled', $disabled->reason);
  }

  /**
   * Invalid imported source mappings fail closed.
   */
  public function testInvalidSourcePolicyFailsClosed(): void {
    $this->config('postmark_webhooks.settings')->set('source_policies', [
      ['server_id' => 'not-numeric', 'message_stream' => 'outbound', 'scope' => 'source'],
    ])->save();
    $decision = $this->container->get(SuppressionPolicyInterface::class)->decide('ok@example.com');
    $this->assertTrue($decision->suppressed);
    $this->assertSame('invalid_source_policy', $decision->reason);
  }

  /**
   * SourceContext rejects untrusted or incomplete values at construction.
   */
  public function testSourceContextTrustBoundary(): void {
    $this->expectException(\InvalidArgumentException::class);
    new SourceContext('not-a-server', 'outbound');
  }

  /**
   * An external consumer can depend only on the interface alias.
   */
  public function testExternalConsumerExample(): void {
    $gate = $this->container->get('postmark_webhooks_consumer_test.send_gate');
    $this->assertInstanceOf(SendGate::class, $gate);
    $this->assertTrue($gate->allows('ok@example.com'));
    $this->container->get('postmark_webhooks.suppression_store')->record([
      'event_type' => 'Bounce',
      'bounce_type' => 'HardBounce',
      'recipient' => 'blocked@example.com',
      'created' => 10,
      'event_key' => str_repeat('b', 64),
    ]);
    $this->assertFalse($gate->allows('blocked@example.com'));
    $this->config('postmark_webhooks.settings')->set('enabled', FALSE)->save();
    $this->assertTrue($gate->allows('blocked@example.com'));
  }

  /**
   * Core mail uses the same decision the public interface returns.
   */
  public function testCoreMailObeysPublicDecision(): void {
    $this->container->get('module_handler')->loadInclude('postmark_webhooks', 'module');
    $this->container->get('postmark_webhooks.suppression_store')->record([
      'event_type' => 'Bounce',
      'bounce_type' => 'HardBounce',
      'recipient' => 'blocked@example.com',
      'created' => 10,
      'event_key' => str_repeat('c', 64),
    ]);
    $policy = $this->container->get(SuppressionPolicyInterface::class);
    $this->assertTrue($policy->decide('blocked@example.com')->suppressed);
    $message = ['to' => 'blocked@example.com', 'send' => TRUE];
    postmark_webhooks_mail_alter($message);
    $this->assertFalse($message['send']);

    $this->config('postmark_webhooks.settings')->set('enabled', FALSE)->save();
    $this->assertFalse($policy->decide('blocked@example.com')->suppressed);
    $disabled = ['to' => 'blocked@example.com', 'send' => TRUE];
    postmark_webhooks_mail_alter($disabled);
    $this->assertTrue($disabled['send']);
  }

  /**
   * Drush JSON objects keep the documented keys and omit secrets.
   */
  public function testDrushJsonKeys(): void {
    $preview = $this->container->get('postmark_webhooks.policy_preview');
    $status = $preview->preview('status@example.com');
    $this->assertSame(
      ['enabled', 'mail_path', 'covered', 'effective_block', 'delivery_guaranteed', 'policy'],
      array_keys($status),
    );
    $this->assertFalse($status['delivery_guaranteed']);
    $this->assertSame(
      ['suppressed', 'reason', 'expires', 'evidence', 'occurred', 'timeBasis'],
      array_keys($status['policy']),
    );
    $diagnostics = $preview->diagnostics();
    $this->assertSame(
      ['enabled', 'secret_configured', 'previous_secret_status', 'coverage', 'intake'],
      array_keys($diagnostics),
    );
    $this->assertSame(['core', 'mailer_plus', 'direct_symfony'], array_keys($diagnostics['coverage']));
    $this->assertTrue($diagnostics['coverage']['core']);
    $this->assertFalse($diagnostics['coverage']['direct_symfony']);
    $this->assertSame(['accepted', 'duplicate', 'rejected'], array_keys($diagnostics['intake']));
  }

}
