<?php

declare(strict_types=1);

namespace Drupal\Tests\postmark_webhooks_mailer\Kernel;

use Drupal\KernelTests\KernelTestBase;
use Drupal\symfony_mailer\EmailInterface;
use Drupal\symfony_mailer\MailerPlus;
use Drupal\symfony_mailer\MailerPlusInterface;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Verifies optional integration through real Mailer Plus processing/transport.
 *
 * @group postmark_webhooks
 */
#[RunTestsInSeparateProcesses]
class PostmarkMailerAdapterTest extends KernelTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'system', 'user', 'filter', 'symfony_mailer', 'postmark_webhooks',
    'postmark_webhooks_mailer', 'postmark_mailer_test', 'postmark_webhooks_test',
  ];

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    if (class_exists(MailerPlus::class)) {
      $extra = ['mailer_transport', 'mailer_policy', 'mailer_override'];
      static::$modules = array_unique(array_merge(static::$modules, $extra));
    }
    parent::setUp();
    $this->installEntitySchema('user');
    $this->installSchema('postmark_webhooks', ['postmark_events', 'postmark_suppression', 'postmark_intake_metrics']);
    $this->installConfig(['postmark_webhooks', 'symfony_mailer', 'filter']);
    if (class_exists(MailerPlus::class)) {
      $this->installConfig(['mailer_policy']);
    }
    $this->config('system.site')->set('name', 'Synthetic test')->set('mail', 'sender@example.com')->save();
    $this->container->get('state')->set('postmark_mailer_test.messages', []);
    $this->container->get('postmark_webhooks.suppression_store')->record([
      'event_type' => 'Bounce',
      'bounce_type' => 'HardBounce',
      'recipient' => 'blocked@example.com',
      'created' => 1,
      'event_key' => str_repeat('a', 64),
    ]);
  }

  /**
   * Builds a native email using the API of the installed supported major.
   */
  private function email(string $to, ?string $cc = NULL, ?string $bcc = NULL): EmailInterface {
    $this->container->get('state')->set('postmark_mailer_test.addresses', [$to, $cc, $bcc]);
    $build = static function (EmailInterface $email): void {
      $email->setFrom('sender@example.com')->setSubject('Synthetic subject');
      $email->setBody(['#markup' => 'Synthetic body']);
    };
    if (class_exists(MailerPlus::class)) {
      $email = $this->container->get(MailerPlusInterface::class)->newEmail('postmark_mailer_test.test');
      $email->addCallback($build, EmailInterface::PHASE_BUILD);
    }
    else {
      $email = $this->container->get('email_factory')->newTypedEmail('postmark_mailer_test', 'test');
      $build($email);
    }
    return $email;
  }

  /**
   * Any suppressed To/Cc/Bcc recipient prevents native transport execution.
   */
  public function testNativeTransportProtection(): void {
    foreach ([
      ['blocked@example.com', NULL, NULL],
      ['safe@example.com', 'BLOCKED@example.com', NULL],
      ['safe@example.com', NULL, 'blocked@example.com'],
    ] as [$to, $cc, $bcc]) {
      $this->email($to, $cc, $bcc)->send();
      $this->assertSame([], $this->container->get('state')->get('postmark_mailer_test.messages', []));
    }
    $this->email('safe@example.com', 'another@example.com')->send();
    $sent = $this->container->get('state')->get('postmark_mailer_test.messages', []);
    $this->assertCount(1, $sent);
    $this->assertSame('Synthetic subject', $sent[0]->getSubject());
    $this->assertSame('safe@example.com', $sent[0]->getTo()[0]->getAddress());
    $this->assertSame('another@example.com', $sent[0]->getCc()[0]->getAddress());
  }

  /**
   * The compatibility path preserves cancellation and uses the same transport.
   */
  public function testCompatibilityTransportProtection(): void {
    $manager = $this->container->get('plugin.manager.mail');
    $manager->mail('postmark_webhooks_test', 'test', 'blocked@example.com', 'en');
    $this->assertSame([], $this->container->get('state')->get('postmark_mailer_test.messages', []));
    $manager->mail('postmark_webhooks_test', 'test', 'safe@example.com', 'en', [
      'headers' => ['Bcc' => 'blocked@example.com'],
    ]);
    $this->assertSame([], $this->container->get('state')->get('postmark_mailer_test.messages', []));
    $manager->mail('postmark_webhooks_test', 'test', 'safe@example.com', 'en');
    $sent = $this->container->get('state')->get('postmark_mailer_test.messages', []);
    $this->assertCount(1, $sent);
    $this->assertSame('safe@example.com', $sent[0]->getTo()[0]->getAddress());
  }

  /**
   * Disabled suppression permits the native message without changing headers.
   */
  public function testDisabledPolicy(): void {
    $this->config('postmark_webhooks.settings')->set('enabled', FALSE)->save();
    $this->email('blocked@example.com')->send();
    $sent = $this->container->get('state')->get('postmark_mailer_test.messages', []);
    $this->assertCount(1, $sent);
    $this->assertSame('blocked@example.com', $sent[0]->getTo()[0]->getAddress());
  }

}
