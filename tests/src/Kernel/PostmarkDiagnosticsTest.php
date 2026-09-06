<?php

declare(strict_types=1);

namespace Drupal\Tests\postmark_webhooks\Kernel;

use Drupal\Core\Site\Settings;
use Drupal\KernelTests\KernelTestBase;
use Drupal\postmark_webhooks\Controller\PostmarkWebhookController;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use Symfony\Component\HttpFoundation\Request;

/**
 * Verifies effective previews and bounded, privacy-safe intake diagnostics.
 *
 * @group postmark_webhooks
 */
#[RunTestsInSeparateProcesses]
class PostmarkDiagnosticsTest extends KernelTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = ['system', 'postmark_webhooks'];

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->installSchema('postmark_webhooks', ['postmark_events', 'postmark_suppression', 'postmark_intake_metrics']);
    $this->installConfig(['postmark_webhooks']);
  }

  /**
   * Disabled and uncovered paths cannot be presented as enforced blocks.
   */
  public function testEffectivePreviewAndPrivacy(): void {
    $this->container->get('postmark_webhooks.suppression_store')->record([
      'event_type' => 'Bounce',
      'bounce_type' => 'HardBounce',
      'recipient' => 'private@example.com',
      'created' => 10,
      'event_key' => str_repeat('a', 64),
    ]);
    $preview = $this->container->get('postmark_webhooks.policy_preview');
    $this->assertTrue($preview->preview('PRIVATE@example.com')['effective_block']);
    $uncovered = $preview->preview('private@example.com', 'direct_symfony');
    $this->assertFalse($uncovered['effective_block']);
    $this->assertTrue($uncovered['policy']['suppressed']);
    $this->assertFalse($uncovered['delivery_guaranteed']);
    $this->assertFalse($preview->preview('private@example.com', 'mailer_plus')['covered']);
    $this->config('postmark_webhooks.settings')->set('enabled', FALSE)->save();
    $disabled = $preview->preview('private@example.com');
    $this->assertFalse($disabled['effective_block']);
    $this->assertSame('disabled', $disabled['policy']['reason']);
    new Settings([]);
    $this->assertFalse($preview->diagnostics()['secret_configured']);
    new Settings(['postmark_webhooks.webhook_secret' => 'never-output-this']);
    $json = json_encode($preview->diagnostics(), JSON_THROW_ON_ERROR);
    $this->assertTrue(json_decode($json, TRUE)['secret_configured']);
    $this->assertStringNotContainsString('never-output-this', $json);
    $this->assertStringNotContainsString('private@example.com', $json);
    $this->assertSame(1, (int) $this->container->get('database')->select('postmark_suppression')->countQuery()->execute()->fetchField());
    $this->assertSame(0, (int) $this->container->get('database')->select('postmark_events')->countQuery()->execute()->fetchField());
  }

  /**
   * Retries count once as evidence and separately as successful duplicates.
   */
  public function testReceiverCounters(): void {
    new Settings(['postmark_webhooks.webhook_secret' => 'test-secret']);
    $controller = PostmarkWebhookController::create($this->container);
    $payload = json_encode([
      'RecordType' => 'Bounce',
      'Type' => 'HardBounce',
      'Email' => 'private@example.com',
      'ID' => 123,
    ]);
    foreach ([$payload, $payload, '{}'] as $index => $body) {
      $request = Request::create('/api/webhooks/postmark', 'POST', [], [], [], ['PHP_AUTH_PW' => 'test-secret'], $body);
      $request->headers->set('Authorization', 'Basic ' . base64_encode('postmark:test-secret'));
      $this->assertSame($index === 2 ? 400 : 200, $controller->receive($request)->getStatusCode());
    }
    $metrics = $this->container->get('postmark_webhooks.intake_metrics');
    $snapshot = $metrics->snapshot();
    foreach (['accepted', 'duplicate', 'rejected'] as $outcome) {
      $this->assertSame(1, $snapshot[$outcome]['total']);
      $this->assertGreaterThan(0, $snapshot[$outcome]['last_seen']);
    }
    $controller->receive(Request::create('/api/webhooks/postmark', 'POST', [], [], [], [], '{}'));
    $this->assertSame($snapshot, $metrics->snapshot(), 'Unauthenticated requests do not write counters.');
    $metrics->record('accepted', 1);
    $this->assertSame(2, $metrics->snapshot()['accepted']['total']);
    $this->assertSame($snapshot['accepted']['last_seen'], $metrics->snapshot()['accepted']['last_seen']);
  }

  /**
   * Updating twice preserves totals and does not fabricate old history.
   */
  public function testUpdateIsIdempotent(): void {
    $this->container->get('database')->schema()->dropTable('postmark_intake_metrics');
    $this->container->get('module_handler')->loadInclude('postmark_webhooks', 'install');
    postmark_webhooks_update_10004();
    $metrics = $this->container->get('postmark_webhooks.intake_metrics');
    $metrics->record('accepted', 10);
    postmark_webhooks_update_10004();
    $this->assertSame(['total' => 1, 'last_seen' => 10], $metrics->snapshot()['accepted']);
    $this->assertSame(['total' => 0, 'last_seen' => NULL], $metrics->snapshot()['duplicate']);
  }

  /**
   * Counter outages cannot change a malformed request's deterministic response.
   */
  public function testRejectedCounterIsBestEffort(): void {
    new Settings(['postmark_webhooks.webhook_secret' => 'test-secret']);
    $this->container->get('database')->schema()->dropTable('postmark_intake_metrics');
    $request = Request::create('/api/webhooks/postmark', 'POST', [], [], [], [], '{}');
    $request->headers->set('Authorization', 'Basic ' . base64_encode('postmark:test-secret'));
    $this->assertSame(400, PostmarkWebhookController::create($this->container)->receive($request)->getStatusCode());
    $this->assertSame(0, (int) $this->container->get('database')->select('postmark_events')->countQuery()->execute()->fetchField());
  }

}
