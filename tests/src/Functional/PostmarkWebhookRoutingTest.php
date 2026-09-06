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
