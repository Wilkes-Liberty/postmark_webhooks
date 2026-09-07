<?php

declare(strict_types=1);

namespace Drupal\Tests\postmark_webhooks\Functional;

use Drupal\Tests\BrowserTestBase;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Verifies Claro operator-form labels, errors, status and confirmation.
 *
 * @group postmark_webhooks
 */
#[RunTestsInSeparateProcesses]
class PostmarkOperatorAccessibilityTest extends BrowserTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = ['postmark_webhooks'];

  /**
   * {@inheritdoc}
   */
  protected $defaultTheme = 'claro';

  /**
   * Settings, preview, inspector, timeline and export keep labels and help.
   */
  public function testReadOnlyOperatorFormsInClaro(): void {
    $this->container->get('postmark_webhooks.suppression_store')->record([
      'event_type' => 'Bounce',
      'bounce_type' => 'HardBounce',
      'recipient' => 'private@example.com',
      'server_id' => '1',
      'message_stream' => 'outbound',
      'created' => 10,
      'event_key' => str_repeat('a', 64),
    ]);
    $this->container->get('database')->insert('postmark_events')->fields([
      'message_id' => 'msg-lookup',
      'event_type' => 'Delivery',
      'recipient' => 'private@example.com',
      'server_id' => '1',
      'message_stream' => 'outbound',
      'occurred' => 50,
      'created' => 100,
      'time_basis' => 'provider',
      'event_key' => str_repeat('b', 64),
    ])->execute();

    foreach ([
      'admin/config/services/postmark-webhook',
      'admin/config/services/postmark-webhook/preview',
      'admin/reports/postmark-suppression',
      'admin/reports/postmark-timeline',
      'admin/reports/postmark-suppression/export',
    ] as $path) {
      $this->drupalGet($path);
      $this->assertSession()->statusCodeEquals(403);
    }

    $this->drupalLogin($this->drupalCreateUser([
      'administer postmark webhook settings',
      'view postmark suppression',
      'export postmark recipient data',
    ]));

    $this->drupalGet('admin/config/services/postmark-webhook');
    $this->assertOperatorShell('postmark-webhooks-settings-help');
    $this->assertSession()->elementExists('css', 'label[for="edit-enabled"]');
    $this->assertSession()->elementExists('css', 'input[name="form_token"]');
    $this->assertSession()->pageTextContains('Webhook secrets stay in settings.php');
    $this->assertSession()->responseNotContains('routing-test-secret');

    $this->drupalGet('admin/config/services/postmark-webhook/preview');
    $this->assertOperatorShell('postmark-webhooks-preview-help');
    $this->submitForm([
      'recipient' => 'preview@example.com',
      'mail_path' => 'core',
      'server_id' => 'abc',
      'message_stream' => 'outbound',
    ], 'Preview policy');
    $this->assertSession()->elementExists('css', '.form-item--error, [aria-invalid="true"]');
    $this->assertSession()->pageTextContains('valid server ID and message stream');
    $this->assertSession()->elementNotExists('css', '#postmark-webhooks-preview-result');
    $this->submitForm([
      'recipient' => 'preview@example.com',
      'mail_path' => 'direct_symfony',
      'server_id' => '',
      'message_stream' => '',
    ], 'Preview policy');
    $this->assertResultRegion('postmark-webhooks-preview-result');
    $this->assertSession()->pageTextContains('This mail path is not protected by this module.');

    $this->drupalGet('admin/reports/postmark-suppression');
    $this->assertOperatorShell('postmark-webhooks-inspector-help');
    $this->assertSession()->elementExists('css', 'label[for="edit-recipient"]');
    $this->assertSession()->addressEquals('admin/reports/postmark-suppression');
    $this->submitForm(['recipient' => 'private@example.com'], 'Inspect suppression');
    $this->assertResultRegion('postmark-webhooks-inspector-result');
    $this->assertSession()->pageTextContains('Suppressed by policy.');
    $this->assertSession()->addressEquals('admin/reports/postmark-suppression');
    $this->submitForm(['recipient' => 'private@example.com'], 'Inspect suppression');
    $this->assertSession()->pageTextContains('Suppressed by policy.');

    $path = 'admin/reports/postmark-timeline';
    $this->drupalGet($path, ['query' => ['message_id' => 'msg-lookup']]);
    $this->assertOperatorShell('postmark-webhooks-timeline-help');
    $this->assertSession()->elementNotExists('css', '#postmark-webhooks-timeline-result');
    $this->assertSession()->pageTextNotContains('Distinct from the MessageID');
    $this->submitForm([
      'message_id' => 'msg-lookup',
      'server_id' => 'abc',
      'message_stream' => 'outbound',
    ], 'Look up timeline');
    $this->assertSession()->elementExists('css', '.form-item--error, [aria-invalid="true"]');
    $this->assertSession()->pageTextContains('numeric server ID');
    $this->assertSession()->elementNotExists('css', '#postmark-webhooks-timeline-result');
    $this->submitForm(['message_id' => 'missing-id', 'server_id' => '', 'message_stream' => ''], 'Look up timeline');
    $this->assertResultRegion('postmark-webhooks-timeline-result');
    $this->assertSession()->elementExists('css', '[role="status"]');
    $this->assertSession()->pageTextContains('No retained events match this lookup');
    $this->submitForm(['message_id' => 'msg-lookup'], 'Look up timeline');
    $this->assertSession()->pageTextContains('This is provider evidence, not proof of inbox placement.');
    $this->assertSession()->addressEquals($path);

    $this->drupalGet('admin/reports/postmark-suppression/export');
    $this->assertOperatorShell('postmark-webhooks-export-help');
    $this->assertSession()->elementExists('css', 'label[for="edit-recipient"]');
    $this->assertSession()->elementExists('css', 'input[name="form_token"]');
    $this->assertSession()->pageTextContains('The browser download itself may not be announced.');
  }

  /**
   * Recovery and erasure keep CSRF, cancel and destructive confirmation.
   */
  public function testDestructiveConfirmationInClaro(): void {
    $database = $this->container->get('database');
    $this->container->get('postmark_webhooks.suppression_store')->record([
      'event_type' => 'Bounce',
      'bounce_type' => 'HardBounce',
      'recipient' => 'private@example.com',
      'server_id' => '1',
      'message_stream' => 'outbound',
      'created' => 10,
      'event_key' => str_repeat('a', 64),
    ]);
    $key = $database->select('postmark_suppression', 's')->fields('s', ['state_key'])->execute()->fetchField();
    $recovery = 'admin/reports/postmark-suppression/recover/' . $key;
    $erase = 'admin/reports/postmark-suppression/erase';
    $this->drupalGet($recovery);
    $this->assertSession()->statusCodeEquals(403);
    $this->drupalGet($erase);
    $this->assertSession()->statusCodeEquals(403);

    $this->drupalLogin($this->drupalCreateUser([
      'view postmark suppression',
      'recover postmark hard bounces',
      'erase postmark recipient history',
    ]));
    $this->drupalGet($recovery);
    $this->assertSession()->elementExists('css', 'form.postmark-webhooks-operator');
    $this->assertSession()->elementExists('css', '#postmark-webhooks-recovery-help[role="note"]');
    $this->assertSession()->elementExists('css', 'input[name="form_token"]');
    $this->assertSession()->elementExists('css', '.button--danger, input.button--danger, button.button--danger');
    $this->assertSession()->linkExists('Cancel');
    $this->assertSession()->pageTextContains('Complaints, manual suppression, unsubscribe and other sources remain protected.');
    $this->assertSession()->elementExists('css', 'input[name="form_token"]')->setValue('forged');
    $this->submitForm([], 'Confirm');
    $this->assertSession()->pageTextContains('The form has become outdated.');
    $this->assertSame(0, (int) $database->select('postmark_operator_audit')->countQuery()->execute()->fetchField());
    $this->drupalGet($recovery);
    $this->clickLink('Cancel');
    $this->assertSession()->addressEquals('admin/reports/postmark-suppression');
    $this->assertTrue($this->container->get('postmark_webhooks.suppression_policy')->decide('private@example.com')->suppressed);

    $this->drupalGet($erase);
    $this->assertOperatorShell('postmark-webhooks-erasure-help');
    $this->submitForm(['recipient' => 'private@example.com'], 'Review history erasure');
    $this->assertSession()->pageTextContains('Minimal suppression evidence, consent protections and audit records remain.');
    $this->assertSession()->elementExists('css', '.button--danger, input.button--danger, button.button--danger');
    $this->assertSession()->linkExists('Cancel');
    $this->clickLink('Cancel');
    $this->assertSession()->addressEquals($erase);
    $this->assertSession()->fieldExists('recipient');
    $this->assertSame(1, (int) $database->select('postmark_suppression')->countQuery()->execute()->fetchField());
  }

  /**
   * Asserts operator help is associated with the form in Claro.
   */
  private function assertOperatorShell(string $help_id): void {
    $this->assertSession()->elementExists('css', 'form.postmark-webhooks-operator');
    $this->assertSession()->elementExists('css', '#' . $help_id . '[role="note"]');
    $this->assertSession()->elementExists('css', 'form[aria-describedby="' . $help_id . '"]');
    $this->assertSession()->responseContains('operator.css');
  }

  /**
   * Asserts a named result region exists as a keyboard focus target.
   */
  private function assertResultRegion(string $id): void {
    $this->assertSession()->elementExists('css', '#' . $id . '.postmark-webhooks-operator__result[role="region"][tabindex="-1"]');
    $this->assertSession()->elementExists('css', '#' . $id . ' [role="status"]');
  }

}
