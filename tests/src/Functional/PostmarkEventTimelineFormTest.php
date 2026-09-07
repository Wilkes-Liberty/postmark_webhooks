<?php

declare(strict_types=1);

namespace Drupal\Tests\postmark_webhooks\Functional;

use Drupal\Tests\BrowserTestBase;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Verifies permission, escaping and empty states for the message timeline.
 *
 * @group postmark_webhooks
 */
#[RunTestsInSeparateProcesses]
class PostmarkEventTimelineFormTest extends BrowserTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = ['postmark_webhooks'];

  /**
   * {@inheritdoc}
   */
  protected $defaultTheme = 'stark';

  /**
   * Lookup is POST-only, escaped, and honest about incomplete history.
   */
  public function testTimelineLookup(): void {
    $this->container->get('database')->insert('postmark_events')->fields([
      'message_id' => 'msg-lookup',
      'event_type' => 'Delivery',
      'recipient' => '<script>alert(1)</script>@example.com',
      'server_id' => '1',
      'message_stream' => '<script>alert(1)</script>',
      'occurred' => 50,
      'created' => 100,
      'time_basis' => 'provider',
      'event_key' => str_repeat('a', 64),
      'description' => 'provider-free-text',
    ])->execute();
    $path = 'admin/reports/postmark-timeline';
    $this->drupalGet($path);
    $this->assertSession()->statusCodeEquals(403);
    $this->drupalGet($path, ['query' => ['message_id' => 'msg-lookup']]);
    $this->assertSession()->statusCodeEquals(403);

    $this->drupalLogin($this->drupalCreateUser(['view postmark suppression']));
    $this->drupalGet($path, ['query' => ['message_id' => 'msg-lookup']]);
    $this->assertSession()->statusCodeEquals(200);
    $this->assertSession()->pageTextContains('not a complete provider archive');
    $this->assertSession()->pageTextNotContains('Delivery is provider evidence');
    $this->assertSession()->addressEquals($path . '?message_id=msg-lookup');
    $this->submitForm(['message_id' => 'missing-id'], 'Look up timeline');
    $this->assertSession()->elementExists('css', '[role="status"]');
    $this->assertSession()->pageTextContains('No retained events match this lookup');
    $this->assertSession()->addressEquals($path);
    $this->submitForm(['message_id' => 'msg-lookup'], 'Look up timeline');
    $this->assertSession()->pageTextContains('Delivery is provider evidence');
    $this->assertSession()->pageTextContains('does not override suppression');
    $this->assertSession()->pageTextContains('Distinct from the MessageID');
    $this->assertSession()->responseNotContains('<script>alert(1)</script>');
    $this->assertSession()->pageTextContains('<script>alert(1)</script>');
    $this->assertSession()->responseNotContains('provider-free-text');
    $this->assertSession()->addressEquals($path);
  }

}
