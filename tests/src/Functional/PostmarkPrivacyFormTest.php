<?php

declare(strict_types=1);

namespace Drupal\Tests\postmark_webhooks\Functional;

use Drupal\Tests\BrowserTestBase;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Verifies permission-separated privacy forms and confirmed batch erasure.
 *
 * @group postmark_webhooks
 */
#[RunTestsInSeparateProcesses]
class PostmarkPrivacyFormTest extends BrowserTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = ['postmark_webhooks'];

  /**
   * {@inheritdoc}
   */
  protected $defaultTheme = 'stark';

  /**
   * Real download and erasure requests respect permissions and confirmation.
   */
  public function testPrivacyForms(): void {
    $database = $this->container->get('database');
    $database->insert('postmark_events')->fields([
      'recipient' => 'private@example.com',
      'event_type' => 'Bounce',
      'bounce_type' => 'HardBounce',
      'created' => 10,
      'description' => 'excluded-provider-text',
      'payload' => 'excluded-secret-body',
    ])->execute();
    $this->container->get('postmark_webhooks.suppression_store')->record([
      'recipient' => 'private@example.com',
      'event_type' => 'Bounce',
      'bounce_type' => 'HardBounce',
      'created' => 10,
      'event_key' => str_repeat('a', 64),
    ]);
    $export = 'admin/reports/postmark-suppression/export';
    $erase = 'admin/reports/postmark-suppression/erase';
    $this->drupalGet($export);
    $this->assertSession()->statusCodeEquals(403);
    $this->drupalGet($erase);
    $this->assertSession()->statusCodeEquals(403);
    $this->drupalLogin($this->drupalCreateUser(['export postmark recipient data']));
    $this->drupalGet($erase);
    $this->assertSession()->statusCodeEquals(403);
    $this->drupalGet($export);
    $this->submitForm(['recipient' => 'private@example.com'], 'Download recipient export');
    $this->assertSession()->responseHeaderContains('Content-Disposition', 'attachment');
    $this->assertSession()->responseHeaderContains('Cache-Control', 'no-store');
    $this->assertSession()->responseNotContains('excluded-secret-body');
    $this->assertSession()->responseNotContains('excluded-provider-text');
    $data = json_decode($this->getSession()->getPage()->getContent(), TRUE, 512, JSON_THROW_ON_ERROR);
    $this->assertCount(1, $data['events']);

    $this->drupalLogin($this->drupalCreateUser(['erase postmark recipient history']));
    $this->drupalGet($erase);
    $this->submitForm(['recipient' => 'private@example.com'], 'Review history erasure');
    $this->assertSession()->pageTextContains('Minimal suppression evidence, consent protections and audit records remain.');
    $this->assertSame(1, (int) $database->select('postmark_events')->countQuery()->execute()->fetchField());
    $this->submitForm(['acknowledge' => TRUE], 'Confirm history erasure');
    $this->assertSession()->pageTextContains('Erased 1 history records. Suppression evidence and audit records remain.');
    $this->assertSame(0, (int) $database->select('postmark_events')->countQuery()->execute()->fetchField());
    $this->assertSame(2, (int) $database->select('postmark_operator_audit')->countQuery()->execute()->fetchField());
    $this->assertTrue($this->container->get('postmark_webhooks.suppression_policy')->decide('private@example.com')->suppressed);
  }

}
