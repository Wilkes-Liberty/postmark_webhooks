<?php

declare(strict_types=1);

namespace Drupal\Tests\postmark_webhooks\Kernel;

use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\Site\Settings;
use Drupal\KernelTests\KernelTestBase;
use Drupal\postmark_webhooks\Controller\PostmarkWebhookController;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use Symfony\Component\HttpFoundation\Request;

/**
 * Verifies ordered subscription transitions without clearing consent evidence.
 *
 * @group postmark_webhooks
 */
#[RunTestsInSeparateProcesses]
class PostmarkSubscriptionTest extends KernelTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = ['postmark_webhooks'];

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->installSchema('postmark_webhooks', ['postmark_events', 'postmark_suppression']);
    $this->installConfig(['postmark_webhooks']);
    new Settings(['postmark_webhooks.webhook_secret' => 'subscription-test-only'] + Settings::getAll());
    $time = $this->createMock(TimeInterface::class);
    $time->method('getRequestTime')->willReturn(1000);
    $time->method('getCurrentTime')->willReturn(1000);
    $this->container->set('datetime.time', $time);
  }

  /**
   * Sends a synthetic provider-shaped SubscriptionChange event.
   */
  private function send(array $changes = []): int {
    $event = $changes + [
      'RecordType' => 'SubscriptionChange',
      'MessageID' => NULL,
      'ServerID' => 23,
      'MessageStream' => 'broadcast',
      'ChangedAt' => gmdate('c', 100),
      'Recipient' => 'subscription@example.com',
      'Origin' => 'Recipient',
      'SuppressSending' => TRUE,
      'SuppressionReason' => 'HardBounce',
    ];
    $request = Request::create('/api/webhooks/postmark', 'POST', [], [], [], [], json_encode($event));
    $request->headers->set('Authorization', 'Basic ' . base64_encode('postmark:subscription-test-only'));
    return PostmarkWebhookController::create($this->container)->receive($request)->getStatusCode();
  }

  /**
   * Newer recovery releases only older hard evidence; equal-time blocks win.
   */
  public function testOrderedHardBounceRecovery(): void {
    $policy = $this->container->get('postmark_webhooks.suppression_policy');
    $this->assertSame(200, $this->send());
    $this->assertTrue($policy->decide('subscription@example.com')->suppressed);
    $release = [
      'ChangedAt' => gmdate('c', 200),
      'SuppressSending' => FALSE,
      'SuppressionReason' => NULL,
      'Origin' => 'Customer',
    ];
    $this->assertSame(200, $this->send($release));
    $this->assertSame(200, $this->send($release));
    $this->assertFalse($policy->decide('subscription@example.com')->suppressed);
    $this->assertSame(200, $this->send(['ChangedAt' => gmdate('c', 150)]));
    $this->assertFalse($policy->decide('subscription@example.com')->suppressed);
    $this->assertSame(200, $this->send(['ChangedAt' => gmdate('c', 200)]));
    $this->assertTrue($policy->decide('subscription@example.com')->suppressed);
    $this->assertSame(4, (int) $this->container->get('database')->select('postmark_events')->countQuery()->execute()->fetchField());
  }

  /**
   * Reactivation never removes complaints or recipient/manual suppression.
   */
  public function testConsentAndComplaintProtection(): void {
    $policy = $this->container->get('postmark_webhooks.suppression_policy');
    foreach (['SpamComplaint', 'ManualSuppression'] as $reason) {
      $recipient = strtolower($reason) . '@example.com';
      $this->assertSame(200, $this->send(['SuppressionReason' => $reason, 'Recipient' => $recipient]));
      $this->assertSame(200, $this->send([
        'Recipient' => $recipient,
        'ChangedAt' => gmdate('c', 200),
        'SuppressSending' => FALSE,
        'SuppressionReason' => NULL,
        'Origin' => 'Admin',
      ]));
      $decision = $policy->decide($recipient);
      $this->assertTrue($decision->suppressed);
      $this->assertStringContainsString($reason, $decision->reason);
    }
  }

  /**
   * Recovery from another source and log retention cannot clear a block.
   */
  public function testSourceAndRetentionBoundaries(): void {
    $policy = $this->container->get('postmark_webhooks.suppression_policy');
    $this->send();
    $this->send([
      'ServerID' => 24,
      'ChangedAt' => gmdate('c', 200),
      'SuppressSending' => FALSE,
      'SuppressionReason' => NULL,
    ]);
    $this->assertTrue($policy->decide('subscription@example.com')->suppressed);
    $this->container->get('postmark_webhooks.event_retention')->purgeBefore(1001);
    $this->assertTrue($policy->decide('subscription@example.com')->suppressed);
    $this->send([
      'ChangedAt' => gmdate('c', 200),
      'SuppressSending' => FALSE,
      'SuppressionReason' => NULL,
    ]);
    $this->assertFalse($policy->decide('subscription@example.com')->suppressed);
    $this->container->get('postmark_webhooks.event_retention')->purgeBefore(1001);
    $this->send(['ChangedAt' => gmdate('c', 150)]);
    $this->assertFalse($policy->decide('subscription@example.com')->suppressed);
  }

  /**
   * Ambiguous state changes fail before consuming retry identity.
   */
  public function testInvalidTransitions(): void {
    foreach ([
      ['SuppressSending' => NULL], ['SuppressSending' => 1],
      ['SuppressionReason' => NULL], ['SuppressionReason' => 'Unknown'],
      ['SuppressSending' => FALSE], ['Origin' => []], ['Origin' => 'Unknown'],
      ['MessageStream' => ''], ['ChangedAt' => ''], ['ServerID' => NULL],
    ] as $changes) {
      $this->assertSame(400, $this->send($changes));
    }
    $this->assertSame(0, (int) $this->container->get('database')->select('postmark_events')->countQuery()->execute()->fetchField());
    $this->assertSame(200, $this->send());
  }

  /**
   * Legacy history gains nullable transition fields without invented consent.
   */
  public function testLegacyFieldUpgrade(): void {
    $database = $this->container->get('database');
    foreach (['suppress_sending', 'suppression_reason', 'origin'] as $field) {
      $database->schema()->dropField('postmark_events', $field);
    }
    $database->insert('postmark_events')->fields([
      'created' => 1,
      'event_type' => 'SubscriptionChange',
      'recipient' => 'legacy@example.com',
    ])->execute();
    $this->container->get('module_handler')->loadInclude('postmark_webhooks', 'install');
    postmark_webhooks_update_10003();
    postmark_webhooks_update_10003();
    $this->assertSame(1, (int) $database->select('postmark_events')->isNull('suppress_sending')->countQuery()->execute()->fetchField());
    $this->assertFalse($this->container->get('postmark_webhooks.suppression_policy')->decide('legacy@example.com')->suppressed);
  }

}
