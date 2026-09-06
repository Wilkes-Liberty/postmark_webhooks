<?php

declare(strict_types=1);

namespace Drupal\Tests\postmark_webhooks\Kernel;

use Drupal\Core\Form\FormState;
use Drupal\KernelTests\KernelTestBase;
use Drupal\postmark_webhooks\Form\PostmarkWebhookSettingsForm;
use Symfony\Component\HttpFoundation\Request;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Checks URL generation under root, subdirectory and trusted proxy contexts.
 *
 * @group postmark_webhooks
 */
#[RunTestsInSeparateProcesses]
class PostmarkEndpointUrlTest extends KernelTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = ['system', 'user', 'postmark_webhooks'];

  /**
   * The settings form uses the routing context, including deployment prefixes.
   */
  public function testEndpointContexts(): void {
    $this->installConfig(['postmark_webhooks']);
    $this->container->get('router.builder')->rebuild();
    $context = $this->container->get('router.request_context');
    foreach (['', '/subdirectory'] as $prefix) {
      $context->setScheme('https');
      $context->setHost('example.com');
      $context->setBaseUrl($prefix);
      $form = PostmarkWebhookSettingsForm::create($this->container)->buildForm([], new FormState());
      $this->assertSame('https://example.com' . $prefix . '/api/webhooks/postmark', (string) $form['webhook_url']['#markup']);
    }
    $request = Request::create('http://internal.example/admin/config/services/postmark-webhook');
    $request->server->set('REMOTE_ADDR', '127.0.0.1');
    $request->headers->set('X-Forwarded-Host', 'public.example.com');
    $request->headers->set('X-Forwarded-Proto', 'https');
    $request->headers->set('X-Forwarded-Prefix', '/proxy');
    $original_proxies = Request::getTrustedProxies();
    $original_headers = Request::getTrustedHeaderSet();
    Request::setTrustedProxies(['127.0.0.1'], Request::HEADER_X_FORWARDED_HOST | Request::HEADER_X_FORWARDED_PROTO | Request::HEADER_X_FORWARDED_PREFIX);
    try {
      $context->fromRequest($request);
      $form = PostmarkWebhookSettingsForm::create($this->container)->buildForm([], new FormState());
      $this->assertSame('https://public.example.com/proxy/api/webhooks/postmark', (string) $form['webhook_url']['#markup']);
    }
    finally {
      Request::setTrustedProxies($original_proxies, $original_headers);
    }
  }

}
