<?php

declare(strict_types=1);

namespace Drupal\Tests\postmark_webhooks\Functional;

use Drupal\Tests\BrowserTestBase;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Verifies protected lookup, escaping and CSRF-confirmed recovery over HTTP.
 *
 * @group postmark_webhooks
 */
#[RunTestsInSeparateProcesses]
class PostmarkInspectorFormTest extends BrowserTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = ['postmark_webhooks'];

  /**
   * {@inheritdoc}
   */
  protected $defaultTheme = 'stark';

  /**
   * Lookup never exposes provider content and viewers cannot change state.
   */
  public function testInspectorAndConfirmedRecovery(): void {
    $database = $this->container->get('database');
    $this->container->get('postmark_webhooks.suppression_store')->record([
      'event_type' => 'Bounce',
      'bounce_type' => 'HardBounce',
      'recipient' => 'private@example.com',
      'server_id' => '1',
      'message_stream' => '<script>alert(1)</script>',
      'created' => 10,
      'event_key' => str_repeat('a', 64),
    ]);
    $key = $database->select('postmark_suppression', 's')->fields('s', ['state_key'])->execute()->fetchField();
    $path = 'admin/reports/postmark-suppression';
    $recovery = $path . '/recover/' . $key;
    $this->drupalGet($path);
    $this->assertSession()->statusCodeEquals(403);
    $this->drupalGet($recovery);
    $this->assertSession()->statusCodeEquals(403);
    $this->drupalLogin($this->drupalCreateUser(['view postmark suppression']));
    $this->drupalGet($path);
    $this->submitForm(['recipient' => 'private@example.com'], 'Inspect suppression');
    $this->assertSession()->pageTextContains('Suppressed by policy.');
    $this->assertSession()->responseNotContains('<script>alert(1)</script>');
    $this->assertSession()->pageTextContains('<script>alert(1)</script>');
    $this->assertSession()->linkNotExists('Review hard-bounce recovery for this source');
    $this->submitForm(['recipient' => 'private@example.com'], 'Inspect suppression');
    $this->assertSession()->pageTextContains('Suppressed by policy.');
    $this->drupalGet($recovery);
    $this->assertSession()->statusCodeEquals(403);

    $this->drupalLogin($this->drupalCreateUser(['view postmark suppression', 'recover postmark hard bounces']));
    $this->drupalGet($recovery);
    $this->assertSession()->pageTextContains('Complaints, manual suppression, unsubscribe and other sources remain protected.');
    $this->assertSession()->elementExists('css', 'input[name="form_token"]')->setValue('forged');
    $this->submitForm([], 'Confirm');
    $this->assertSession()->pageTextContains('The form has become outdated.');
    $this->assertSame(0, (int) $database->select('postmark_operator_audit')->countQuery()->execute()->fetchField());
    $this->drupalGet($recovery);
    $this->submitForm([], 'Confirm');
    $this->assertSession()->pageTextContains('Hard-bounce recovery recorded.');
    $this->assertSame(1, (int) $database->select('postmark_operator_audit')->countQuery()->execute()->fetchField());
    $this->assertFalse($this->container->get('postmark_webhooks.suppression_policy')->decide('private@example.com')->suppressed);
  }

}
