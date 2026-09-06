<?php

declare(strict_types=1);

namespace Drupal\Tests\postmark_webhooks\Functional;

use Drupal\Tests\BrowserTestBase;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Tests HTTP routing, authentication, settings access and secret exclusion.
 *
 * @group postmark_webhooks
 */
#[RunTestsInSeparateProcesses]
class PostmarkWebhookRoutingTest extends BrowserTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = ['postmark_webhooks'];

  /**
   * {@inheritdoc}
   */
  protected $defaultTheme = 'stark';

  /**
   * The protected preview is read-only and reports disabled or uncovered paths.
   */
  public function testPolicyPreviewForm(): void {
    $path = 'admin/config/services/postmark-webhook/preview';
    $this->drupalGet($path);
    $this->assertSession()->statusCodeEquals(403);
    $this->drupalLogin($this->drupalCreateUser(['administer postmark webhook settings']));
    $this->drupalGet($path);
    $this->submitForm(['recipient' => 'preview@example.com', 'mail_path' => 'direct_symfony'], 'Preview policy');
    $this->assertSession()->pageTextContains('This mail path is not protected by this module.');
    $this->config('postmark_webhooks.settings')->set('enabled', FALSE)->save();
    $this->drupalGet($path);
    $this->submitForm(['recipient' => 'preview@example.com', 'mail_path' => 'core'], 'Preview policy');
    $this->assertSession()->pageTextContains('Suppression is disabled.');
    $this->submitForm(['recipient' => 'second@example.com', 'mail_path' => 'core'], 'Preview policy');
    $this->assertSession()->pageTextContains('Suppression is disabled.');
    $this->assertSession()->responseHeaderContains('Cache-Control', 'no-cache');
    $database = $this->container->get('database');
    foreach (['postmark_events', 'postmark_suppression', 'postmark_intake_metrics'] as $table) {
      $this->assertSame(0, (int) $database->select($table)->countQuery()->execute()->fetchField());
    }
  }

  /**
   * Settings require permission and render a routed, secret-free endpoint URL.
   */
  public function testSettingsUrlAndAccess(): void {
    $this->drupalGet('admin/config/services/postmark-webhook');
    $this->assertSession()->statusCodeEquals(403);
    $this->drupalLogin($this->drupalCreateUser(['administer postmark webhook settings']));
    $this->writeSettings([
      'settings' => [
        'postmark_webhooks.webhook_secret' => (object) [
          'value' => 'routing-test-secret',
          'required' => TRUE,
        ],
      ],
    ]);
    $this->drupalGet('admin/config/services/postmark-webhook');
    $this->assertSession()->statusCodeEquals(200);
    $this->assertSession()->pageTextContains($this->getAbsoluteUrl('api/webhooks/postmark'));
    $this->assertSession()->responseNotContains('routing-test-secret');
  }

  /**
   * The HTTP route enforces method and Basic Auth before storing an event.
   */
  public function testWebhookHttpAuthentication(): void {
    $this->writeSettings([
      'settings' => [
        'postmark_webhooks.webhook_secret' => (object) [
          'value' => 'routing-test-secret',
          'required' => TRUE,
        ],
      ],
    ]);
    $client = $this->getHttpClient();
    $url = $this->getAbsoluteUrl('api/webhooks/postmark');
    $options = [
      'http_errors' => FALSE,
      'json' => [
        'RecordType' => 'Bounce',
        'Type' => 'HardBounce',
        'Email' => 'http@example.com',
      ],
    ];
    $this->assertSame(401, $client->post($url, $options)->getStatusCode());
    $options['auth'] = ['postmark', 'routing-test-secret'];
    $this->assertSame(200, $client->post($url, $options)->getStatusCode());
    $this->assertSame(200, $client->post($url, $options)->getStatusCode());
    $this->assertSame(405, $client->get($url, ['http_errors' => FALSE])->getStatusCode());
    $this->assertSame(1, (int) $this->container->get('database')->select('postmark_events')->countQuery()->execute()->fetchField());
  }

}
