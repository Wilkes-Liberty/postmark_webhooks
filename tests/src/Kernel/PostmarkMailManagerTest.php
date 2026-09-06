<?php

declare(strict_types=1);

namespace Drupal\Tests\postmark_webhooks\Kernel;

use Drupal\KernelTests\KernelTestBase;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Verifies recipient checks before the real mail manager reaches its transport.
 *
 * @group postmark_webhooks
 */
#[RunTestsInSeparateProcesses]
class PostmarkMailManagerTest extends KernelTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = ['system', 'postmark_webhooks', 'postmark_webhooks_test'];

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->installSchema('postmark_webhooks', ['postmark_events', 'postmark_suppression']);
    $this->installConfig(['postmark_webhooks']);
    $this->config('system.site')->set('mail', 'sender@example.com')->save();
    $this->config('system.mail')->set('interface', ['default' => 'test_mail_collector'])->save();
    $this->container->get('state')->set('system.test_mail_collector', []);
    $this->container->get('postmark_webhooks.suppression_store')->record([
      'event_type' => 'Bounce',
      'bounce_type' => 'HardBounce',
      'recipient' => 'blocked@example.com',
      'created' => $this->container->get('datetime.time')->getRequestTime(),
      'event_key' => str_repeat('a', 64),
    ]);
  }

  /**
   * Suppressed or malformed recipient lists never reach transport.
   */
  public function testBlockedMailNeverReachesTransport(): void {
    $manager = $this->container->get('plugin.manager.mail');
    $cases = [
      ['safe@example.com, BLOCKED@example.com', []],
      ['"Safe, Person" <safe@example.com>, Blocked <blocked@example.com>', []],
      ['safe@example.com', ['Cc' => 'blocked@example.com']],
      ['safe@example.com', ['bCC' => ['Safe <safe@example.com>', 'blocked@example.com']]],
      ['safe@example.com', ['To' => 'blocked@example.com']],
      ['safe@example.com, invalid', []],
      ['"Unclosed <safe@example.com>', []],
      ['safe@example.com,', []],
    ];
    foreach ($cases as [$to, $headers]) {
      $message = $manager->mail('postmark_webhooks_test', 'test', $to, 'en', ['headers' => $headers]);
      $this->assertFalse($message['send']);
      $this->assertNull($message['result']);
    }
    $this->assertEmpty($this->container->get('state')->get('system.test_mail_collector', []));
  }

  /**
   * Allowed mail reaches the test collector; prior cancellation is retained.
   */
  public function testAllowedAndPreviouslyCancelledMail(): void {
    $manager = $this->container->get('plugin.manager.mail');
    $to = '"Safe, Person" <safe@example.com>, Another <another@example.com>';
    $message = $manager->mail('postmark_webhooks_test', 'test', $to, 'en');
    $this->assertTrue($message['result']);
    $this->assertSame($to, $message['to']);
    $this->assertCount(1, $this->container->get('state')->get('system.test_mail_collector', []));
    $cancelled = $manager->mail('postmark_webhooks_test', 'test', $to, 'en', ['block' => TRUE]);
    $this->assertFalse($cancelled['send']);
    $this->assertNull($cancelled['result']);
    $this->assertCount(1, $this->container->get('state')->get('system.test_mail_collector', []));
  }

}
