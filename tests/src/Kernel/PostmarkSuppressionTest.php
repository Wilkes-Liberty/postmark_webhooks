<?php

declare(strict_types=1);

namespace Drupal\Tests\postmark_webhooks\Kernel;

use Drupal\KernelTests\KernelTestBase;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Kernel tests for Postmark bounce/spam suppression logic.
 *
 * Exercises _postmark_webhooks_suppression_reason() and the
 * hook_mail_alter() implementation against real rows in the
 * postmark_events table installed by the module's hook_schema().
 *
 * @group postmark_webhooks
 */
#[RunTestsInSeparateProcesses]
class PostmarkSuppressionTest extends KernelTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = ['postmark_webhooks'];

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    // Create the postmark_events table from the module's hook_schema().
    $this->installSchema('postmark_webhooks', ['postmark_events']);
    // Install the default module settings (enabled, suppression windows).
    $this->installConfig(['postmark_webhooks']);
    // Load the .module file so its functions are available.
    \Drupal::moduleHandler()->loadInclude('postmark_webhooks', 'module');
  }

  /**
   * Inserts a postmark_events row.
   */
  private function insertEvent(array $overrides = []): void {
    $row = $overrides + [
      'created' => \Drupal::time()->getRequestTime(),
      'event_type' => '',
      'message_id' => '',
      'recipient' => '',
      'bounce_type' => '',
      'description' => '',
      'payload' => NULL,
    ];
    \Drupal::database()->insert('postmark_events')->fields($row)->execute();
  }

  /**
   * Calls the private suppression-reason helper with current config.
   */
  private function reasonFor(string $email): ?string {
    $config = \Drupal::config('postmark_webhooks.settings');
    return _postmark_webhooks_suppression_reason($email, $config);
  }

  /**
   * The schema table exists and config defaults are present.
   */
  public function testSchemaAndConfigInstalled(): void {
    $this->assertTrue(
      \Drupal::database()->schema()->tableExists('postmark_events'),
      'postmark_events table was created from hook_schema().'
    );
    $config = \Drupal::config('postmark_webhooks.settings');
    $this->assertTrue((bool) $config->get('enabled'));
    $this->assertSame(30, (int) $config->get('bounce_suppression_days'));
    $this->assertSame(0, (int) $config->get('complaint_suppression_days'));
    $this->assertSame(90, (int) $config->get('event_retention_days'));
  }

  /**
   * Cron deletes events older than the retention window.
   */
  public function testCronPurgesEventsOlderThanRetention(): void {
    $now = \Drupal::time()->getRequestTime();
    $this->insertEvent([
      'created' => $now - (91 * 86400),
      'recipient' => 'old@example.com',
      'event_type' => 'Delivery',
    ]);
    $this->insertEvent([
      'created' => $now - (10 * 86400),
      'recipient' => 'new@example.com',
      'event_type' => 'Delivery',
    ]);
    postmark_webhooks_cron();
    $remaining = \Drupal::database()->select('postmark_events', 'pe')
      ->fields('pe', ['recipient'])
      ->execute()
      ->fetchCol();
    $this->assertSame(['new@example.com'], $remaining);
  }

  /**
   * Suppression logs keep the domain and drop the mailbox.
   */
  public function testRecipientRedactionKeepsDomainOnly(): void {
    $this->assertSame('*@example.com', _postmark_webhooks_redact_recipient('ada@example.com'));
    $this->assertSame('[redacted]', _postmark_webhooks_redact_recipient('not-an-email'));
  }

  /**
   * A clean recipient with no events is never suppressed.
   */
  public function testCleanRecipientNotSuppressed(): void {
    $this->assertNull($this->reasonFor('clean@example.com'));
  }

  /**
   * Hard bounces suppress permanently regardless of age.
   */
  public function testHardBounceSuppressedPermanently(): void {
    // An old hard bounce (1 year ago) must still suppress.
    $this->insertEvent([
      'event_type' => 'Bounce',
      'bounce_type' => 'HardBounce',
      'recipient' => 'hard@example.com',
      'created' => \Drupal::time()->getRequestTime() - (365 * 86400),
    ]);
    $reason = $this->reasonFor('hard@example.com');
    $this->assertNotNull($reason);
    $this->assertStringContainsString('hard bounce', $reason);
    $this->assertStringContainsString('HardBounce', $reason);
  }

  /**
   * Soft bounces suppress only inside the configured day window.
   */
  public function testSoftBounceRespectsWindow(): void {
    $now = \Drupal::time()->getRequestTime();

    // Recent soft bounce (5 days ago) -> suppressed (window is 30 days).
    $this->insertEvent([
      'event_type' => 'Bounce',
      'bounce_type' => 'SoftBounce',
      'recipient' => 'recent-soft@example.com',
      'created' => $now - (5 * 86400),
    ]);
    $recent = $this->reasonFor('recent-soft@example.com');
    $this->assertNotNull($recent);
    $this->assertStringContainsString('soft bounce', $recent);

    // Old soft bounce (60 days ago) -> outside 30-day window -> NOT suppressed.
    $this->insertEvent([
      'event_type' => 'Bounce',
      'bounce_type' => 'SoftBounce',
      'recipient' => 'old-soft@example.com',
      'created' => $now - (60 * 86400),
    ]);
    $this->assertNull($this->reasonFor('old-soft@example.com'));
  }

  /**
   * Spam complaints suppress permanently when complaint_suppression_days is 0.
   */
  public function testSpamComplaintSuppressedPermanently(): void {
    // Old spam complaint; complaint_suppression_days defaults to 0 = permanent.
    $this->insertEvent([
      'event_type' => 'SpamComplaint',
      'recipient' => 'spammer@example.com',
      'created' => \Drupal::time()->getRequestTime() - (400 * 86400),
    ]);
    $reason = $this->reasonFor('spammer@example.com');
    $this->assertNotNull($reason);
    $this->assertStringContainsString('spam complaint', $reason);
    $this->assertStringContainsString('SpamComplaint', $reason);
  }

  /**
   * Recipient matching is case-insensitive in both directions.
   *
   * Guards against the PostgreSQL case-sensitive exact-match bug: a
   * mixed-case stored recipient must still suppress a lowercased send, and a
   * mixed-case send must still be caught by a lowercased stored recipient.
   */
  public function testSuppressionIsCaseInsensitive(): void {
    // Mixed-case stored recipient (simulates a pre-existing, unnormalized row).
    $this->insertEvent([
      'event_type' => 'Bounce',
      'bounce_type' => 'HardBounce',
      'recipient' => 'Bounce@Example.com',
    ]);
    // A lowercase send must be suppressed by the mixed-case stored row.
    $lower = $this->reasonFor('bounce@example.com');
    $this->assertNotNull($lower, 'Lowercase send matches a mixed-case stored recipient.');
    $this->assertStringContainsString('hard bounce', $lower);
    // A differently-cased send must also be suppressed.
    $this->assertNotNull(
      $this->reasonFor('BOUNCE@EXAMPLE.COM'),
      'Uppercase send matches a mixed-case stored recipient.'
    );

    // Normalized (lowercase) stored recipient — as written on insert going
    // forward — must be caught by a mixed-case send.
    $this->insertEvent([
      'event_type' => 'SpamComplaint',
      'recipient' => 'spam@example.com',
    ]);
    $mixed = $this->reasonFor('SpAm@Example.Com');
    $this->assertNotNull($mixed, 'Mixed-case send matches a lowercase stored recipient.');
    $this->assertStringContainsString('spam complaint', $mixed);
  }

  /**
   * Delivery/Open events are log-only and never suppress.
   */
  public function testDeliveryEventNotSuppressed(): void {
    $this->insertEvent([
      'event_type' => 'Delivery',
      'recipient' => 'delivered@example.com',
    ]);
    $this->assertNull($this->reasonFor('delivered@example.com'));
  }

  /**
   * Disabling the module via config short-circuits hook_mail_alter().
   */
  public function testDisabledConfigSkipsSuppression(): void {
    $this->insertEvent([
      'event_type' => 'Bounce',
      'bounce_type' => 'HardBounce',
      'recipient' => 'hard@example.com',
    ]);
    \Drupal::configFactory()
      ->getEditable('postmark_webhooks.settings')
      ->set('enabled', FALSE)
      ->save();

    $message = ['to' => 'hard@example.com', 'send' => TRUE];
    postmark_webhooks_mail_alter($message);
    // When disabled, the hook returns early and never touches send.
    $this->assertTrue($message['send']);
  }

  /**
   * The hook_mail_alter() implementation blocks a suppressed recipient.
   */
  public function testMailAlterBlocksSuppressedRecipient(): void {
    $this->insertEvent([
      'event_type' => 'Bounce',
      'bounce_type' => 'HardBounce',
      'recipient' => 'hard@example.com',
    ]);

    // Bare address.
    $message = ['to' => 'hard@example.com', 'send' => TRUE];
    postmark_webhooks_mail_alter($message);
    $this->assertFalse($message['send'], 'Mail to a hard-bounced address is suppressed.');

    // Display-name form "Name <email>" should also be parsed and suppressed.
    $named = ['to' => 'Hard Bounce <hard@example.com>', 'send' => TRUE];
    postmark_webhooks_mail_alter($named);
    $this->assertFalse($named['send'], 'Display-name address form is parsed and suppressed.');
  }

  /**
   * The hook_mail_alter() implementation leaves clean recipients untouched.
   */
  public function testMailAlterAllowsCleanRecipient(): void {
    $message = ['to' => 'clean@example.com', 'send' => TRUE];
    postmark_webhooks_mail_alter($message);
    $this->assertTrue($message['send'], 'Mail to a clean address is not suppressed.');
  }

}
